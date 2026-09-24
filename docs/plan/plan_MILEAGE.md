# План MILEAGE: пробег ≤ 400 000 км, иначе — review

Сверено с `docs/setup/code_map.md` (раздел «Куда встанет правило») и кодом
`backend/src/Domain/`, `backend/config/rules.php`. Порог 400 000 — новое
значение решения; существующий `vehicle.max_mileage_km = 500000` —
валидационный предел, его не трогаем.

## 1. Файлы

- `backend/config/rules.php` — в блок `vehicle` добавить ключ `mileage_review_max_km => 400000`.
- `backend/src/Domain/DecisionEngine.php` — новое свойство `mileageReviewMaxKm`, порог из `$thresholds`; сигнатура `decide(float $ltv, int $mileage): string`; после ветки approve/review по LTV: если по LTV было бы `APPROVE` и `mileage > mileageReviewMaxKm` → `REVIEW`.
- `backend/src/AppFactory.php:37` — конструктору `DecisionEngine` передать `$rules['ltv'] + ['mileage_review_max_km' => $rules['vehicle']['mileage_review_max_km']]` (или отдельным аргументом — см. шаг 2).
- `backend/src/Domain/AssessmentService.php:33` — вызов `decide($ltv, $input['mileage'])`.
- `tests/Unit/DecisionEngineTest.php` — конструктор движка получает новый порог; новые кейсы пробега.
- `tests/Unit/AssessmentServiceTest.php` — сквозные кейсы: высокий пробег понижает approve до review.
- `docs/setup/code_map.md` — обновить «Что уже проверяется про пробег» (правило переехало в DecisionEngine).

Всё, чего нет в списке, при реализации трогать нельзя.

## 2. Шаги

1. Добавить `mileage_review_max_km => 400000` в `rules.vehicle`.
2. В `DecisionEngine`: принять порог пробега (расширить массив `$thresholds` ключом `mileage_review_max_km`), сохранить в свойство, изменить сигнатуру `decide(float $ltv, int $mileage): string` (второй аргумент обязателен — пробег пустым не бывает: валидатор либо отвергает заявку, либо отдаёт int).
3. В `DecisionEngine::decide` логика: сначала решение по LTV; если оно `APPROVE` и `mileage > mileageReviewMaxKm` → вернуть `REVIEW`. Ветки `REVIEW` и `REJECT` по LTV пробегом не смягчаются и не ужесточаются.
4. В `AppFactory` прокинуть новый порог в конструктор `DecisionEngine`.
5. В `AssessmentService::assess` передать `$input['mileage']` вторым аргументом в `decide`.
6. Обновить оба юнит-теста (конструкторы движка + новые случаи) и добавить сквозные кейсы в `AssessmentServiceTest`.
7. Прогнать `make lint` и `make test`.
8. Обновить `docs/setup/code_map.md`.

## 3. Тесты

Граничные значения (LTV во всех случаях в зелёной зоне, чтобы изолировать пробег):

- `mileage = 399999` → `approve` (порог не превышен).
- `mileage = 400000` → `approve` (400 000 — «не больше», граница включается).
- `mileage = 400001` → `review` (порог превышен — approve понижен до review).
- Пустой пробег (`mileage` отсутствует в payload) → `ValidationException` с ошибкой по полю `mileage` (каст `(int) null` даёт 0? — нет: валидатор читает `?? -1`, значит пусто → -1 → ошибка «Пробег от 0 до 500000»; тест фиксирует именно это поведение — до движка не доходит).

Дополнительно:

- `mileage = 400001` + LTV в серой зоне (72.3) → `review` (без изменения — уже review).
- `mileage = 400001` + LTV > 85 → `reject` (пробег не спасает от reject).
- `mileage = 400001` + `approved_limit` в ответе = 0 (review ≠ approve, лимит не выдаётся).

## 4. Риски

- **`max_mileage_km = 500000`**: значения 400 001–500 000 валидны и попадают в `DecisionEngine` — ок; но если когда-то `mileage_review_max_km` станут больше `max_mileage_km`, правило молча умрёт (всё сверх валидации отсекается). Можно добавить в конструктор движка `assert`/проверку `mileage_review_max_km <= max_mileage_km` — на усмотрение ревью.
- **Сигнатура `decide` стала двухаргументной** — сломаются все внешние вызовы; в репо их два (`AssessmentService`, тесты), оба в плане. Любой сторонний код, дергавший `decide($ltv)`, отвалится.
- **Пропуск `mileage` теперь иная семантика**: раньше «нет поля» и «поле есть» были одинаково валидационной зоной; правило не меняет валидацию, но тесты на «пустой пробег» нужно перепроверить после правки — поведение не должно измениться.
- **Двойной источник порогов** (`ltv` и `vehicle` в конструкторе движка) — можно ошибиться в `AppFactory` и тестах; типизация массива порогов в docblock поможет.
- **`approved_limit`**: при понижении approve → review лимит обязан стать 0 — легко забыть, тест-кейс включён.
- Не входит: валидация `max_mileage_km`, LOAN-12 (`ltv_by_age`), сравнение пробега «по одометру против слов клиента» (CASE-08 / client_note), фронтенд, БД и репозиторий.

## Вопросы к заказчику

1. Граница 400 000 включительно — «не больше» означает approve при ровно 400 000? (В плане — да.)
2. Пробег выше порога при LTV уже в review/reject — оставляем как есть или пробег может ужесточать review до reject?
3. Должен ли ответ содержать причину понижения до review (отдельное поле/код причины), или решение без объяснений?
4. Существующий валидационный предел 500 000 остаётся без изменений?
5. Пустой пробег — ValidationException без изменений, или для пробега хотим отдельное поведение (например, трактовать как «неизвестно» и сразу review)?
