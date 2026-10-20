# HW2 — проверка формы заявки через мобильный эмулятор

Дата: 2026-10-20
Стенд: эмулятор Pixel_7 (Android 14, arm64), Chrome 155, AVD `Pixel_7` запущен через `emulator -avd Pixel_7 -no-snapshot -no-audio`.
Backend: поднят через `docker compose up -d --build` (порт 8080), MySQL и backend в compose-сети healthy.
Бэкенд-правила, которые используются: `backend/config/rules.php` — порог
`vehicle.mileage_review_max_km = 400000` (`config/rules.php:25`). При пробеге
строго больше этого порога решение понижается с `approve` до `review`.

## Что я делал по шагам

1. Проверил устройства через `plugin-android_device.list` — эмулятор сначала
   не был подключён. Открыл Android Studio SDK (`~/Library/Android/sdk`),
   увидел AVDы (`Medium_Phone`, `Pixel`, `Pixel_7`). Поднял
   `emulator -avd Pixel_7 -no-snapshot -no-audio` в фоне, дождался
   `adb devices` → `emulator-5554 device`.
2. Параллельно поднял backend: Docker Desktop изначально не запущен, поэтому
   `open -a Docker`, дождался демона, затем `docker compose up -d --build`.
   Дождался `curl http://localhost:8080/health` → 200.
3. Переключил target плагина в Android (`plugin-android_device.set_target
   target=android`), подключил модуль `browser` (`enable_module
   module=browser`).
4. Попробовал `plugin-android_browser.open url=http://10.0.2.2:8080/`. Это
   **не удалось**: плагин вернул `[TOOL_FAILED] Web is not installed. Run
   \`mcp-devices install web\`.` Проверил `adb shell pm list packages |
   grep -i chrome` — Chrome (`com.android.chrome`, версия 155) и WebView
   установлены в эмуляторе, но MCP browser-модуль всё равно отказывается
   работать. Запасного варианта у плагина нет.
5. Поэтому дальше вёл Chrome руками через `adb shell am start ... VIEW
   -d http://10.0.2.2:8080/` и взаимодействовал через `input tap/text/
   keyevent` + `uiautomator dump` (текстовое содержимое страницы и
   координаты элементов). Это эквивалент того, что делал бы MCP browser,
   но через ADB.
6. Снимок UI (`ui.action:tree`) подтвердил, что форма загрузилась: поля
   `applicant_ref`, `vin`, `year=2019`, `mileage=84000`, `market_value=
   900000`, `requested_amount=450000`, `term_months=24`, кнопки
   «Отправить заявку» и «Только рассчитать LTV».
7. Тапнул по полю «Пробег, км» (`input tap text="84000"`). В первой попытке
   очистка через 6× `BACKSPACE` сработала частично — в поле осталось
   «840», потому что под полем появилась панель подсказок Chrome, которая
   съедала фокус, и часть нажатий уходила не в поле. На повторе через
   `adb shell input keyevent 67` ×10 поле стало пустым (проверил
   `uiautomator dump`, `resource-id="mileage"`, `text=""`).
8. Ввёл `400001` через `adb shell input text "400001"`. Дамп подтвердил:
   `<node ... resource-id="mileage" text="400001" ... />`.
9. Снял фокус с поля (`input keyevent KEYCODE_BACK` — закрыл экранную
   клавиатуру), `ui.tree` показал все поля формы, включая «Пробег, км»
   со значением 400001.
10. Тапнул «Отправить заявку» (`input tap 291 2256`). После сабмита
    `ui.tree` начал показывать секцию «Решение» с текстом `REVIEW`.
11. Сразу после сабмита блок `#result` оказался ниже видимой области
    (WebView `bounds=[0,283][1080,2274]`, элементы результата все в
    `bounds=[640,2274][984,2274]` — схлопнуты у нижней кромки).
    Попробовал свайп по странице через `input swipe` — **первый свайп
    Chrome интерпретировал как back-gesture и переключил на
    предыдущую вкладку** (yandex.ru). После этого заявка потерялась,
    пришлось переоткрыть `http://10.0.2.2:8080/` через `am start ...
    VIEW`, заново очистить поле и снова ввести 400001, снова сабмитнуть.
12. На повторе страница после сабмита показала блок результата с теми
    же значениями, но всё ещё схлопнутым у y=2274. Чтобы увидеть блок,
    прокрутил WebView через `input keyevent KEYCODE_PAGE_DOWN` дважды.
    После этого `uiautomator dump` показал видимый блок `#result`:
    - Решение: `REVIEW`
    - LTV: `50 %`
    - Возраст авто: `7 лет`
    - Одобренный лимит: `0 ₽`
    - Номер заявки: `56`
13. Снял скриншот: `adb -s emulator-5554 exec-out screencap -p >
    docs/hw2/mobile_check.png`. Получился PNG 1080×2400, 136 КБ.
    Перед снятием повторно сдампил UI — поле `mileage=400001` и блок
    `#result` с REVIEW/50 %/7 лет/0 ₽/56 видны одновременно.

## Решение системы

`decision: review`, `ltv: 50 %`, `vehicle_age: 7 лет`, `approved_limit: 0 ₽`,
id заявки `56`.

Это совпадает с правилами из `backend/config/rules.php:25`: при пробеге
400001 км (строго больше `mileage_review_max_km = 400000`) система
понижает решение с `approve` до `review`, даже если LTV (50 %) сам по
себе укладывается в порог `ltv.approve_max = 60 %` (`rules.php:46`).
Подтверждено и независимым запросом:

```
curl -X POST http://localhost:8080/api/applications \
  -d '{"applicant_ref":"CL-0025","vin":"XTA21099998765432","year":2019,
       "mileage":400001,"market_value":900000,"requested_amount":450000,
       "term_months":24}'
→ {"id":55,"vehicle_age":7,"ltv":50.0,"decision":"review","approved_limit":0}
```

## Что не удалось / пришлось обходить

- `plugin-android_browser.open` не работает: плагин требует свой
  собственный web-профайл (`Web is not installed`), хотя Chrome в
  эмуляторе стоит. Поэтому форму гнал через `adb shell am start` +
  `input tap/text/keyevent` + `uiautomator dump`. Логика взаимодействия
  та же, что обещает MCP browser, но без его обвязки.
- Очистка поля «Пробег» через UI-input потребовала нескольких попыток:
  панель автоподсказок Chrome перехватывает фокус. В итоге рабочий путь —
  тап по полю → серия `adb shell input keyevent 67` (BACKSPACE) до
  опустошения → `input text "400001"`.
- Свайп по странице для скролла вниз к блоку результата Chrome
  интерпретирует как back-gesture, если он идёт через системный бар
  жестов. Поэтому скроллил через `KEYCODE_PAGE_DOWN` после возврата
  фокуса в WebView.

## Файлы

- `docs/hw2/mobile_check.png` — снимок экрана эмулятора с открытой
  формой (поле «Пробег, км = 400001») и блоком решения «REVIEW /
  LTV 50 % / 7 лет / 0 ₽ / id 56».
- `docs/hw2/mobile_check.md` — этот отчёт.
