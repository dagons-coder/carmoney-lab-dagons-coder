# E2E-проверка UI: пробег 400001 → review, пробег 600000 → ошибка

Дата: 2026-10-19. Бэкенд: http://localhost:8080 (`make ps` показал Up/healthy,
`curl http://localhost:8080/health` → `200`). UI — форма из `frontend/index.html`.

Скриншоты лежат рядом:
- `docs/setup/e2e_mileage_400001_review.png`
- `docs/setup/e2e_mileage_600000_error.png`

Все цитаты ниже — дословно из ответов API и DOM-инспекции, без пересказа.

---

## Общий старт

1. Playwright: `await page.goto('http://localhost:8080')`.
2. После загрузки Playwright сообщил:
   - `Page URL: http://localhost:8080/`
   - `Page Title: Предварительная оценка заявки — carmoney-lab`
   - В консоли — 1 ошибка: `Failed to load resource: the server responded with a status of 404 (Not Found) @ http://localhost:8080/favicon.ico:0`. Это favicon, к логике формы отношения не имеет.
3. Заголовок страницы (из snapshot):
   `Предварительная оценка заявки на заём под ПТС`.
4. Поля формы и значения по умолчанию (из snapshot):
   - Идентификатор клиента: `CL-0025`
   - VIN: `XTA21099998765432`
   - Год выпуска: `2019`
   - Пробег, км: `84000`
   - Оценочная стоимость, ₽: `900000`
   - Запрошенная сумма, ₽: `450000`
   - Срок, месяцев: `24`

---

## Кейс 1 — пробег 400001

Ожидание: решение `review`.

### Шаги

1. В поле «Пробег, км» ввёл `400001` через `getByRole('spinbutton', { name: 'Пробег, км' }).fill('400001')`.
2. Проверил DOM до отправки:
   ```
   { mileage: "400001", requested_amount: "450000", market_value: "900000" }
   ```
3. Нажал кнопку «Отправить заявку» (`getByRole('button', { name: 'Отправить заявку' }).click()`).

### Что показала система

Снимок DOM результата (через `page.evaluate`):

```
{
  "result_hidden": false,
  "errors_hidden": true,
  "decision": "review",
  "decision_class": "decision decision--review",
  "ltv": "50 %",
  "age": "7 лет",
  "limit": "0 ₽",
  "id": "50"
}
```

Сырой ответ API (повторный `curl` для сверки, без UI):

```
{"id":51,"vehicle_age":7,"ltv":50.0,"decision":"review","approved_limit":0}
```

UI и API согласованы по смыслу: `decision = review`, `ltv = 50`, `approved_limit = 0`,
возраст авто 7 лет (2026 − 2019). Ид заявки в UI = 50, при повторном прямом curl
вернулся 51 — это нормально, счётчик автоинкрементный.

Скриншот: `docs/setup/e2e_mileage_400001_review.png`.

---

## Кейс 2 — пробег 600000

Ожидание: ошибка валидации.

### Шаги

1. Перезагрузил страницу (`page.goto('http://localhost:8080')`) — поля вернулись к значениям по умолчанию, секция результата скрыта.
2. В поле «Пробег, км» ввёл `600000` (`getByRole('spinbutton', { name: 'Пробег, км' }).fill('600000')`).
3. Проверил DOM до отправки:
   ```
   { mileage: "600000" }
   ```
4. Нажал кнопку «Отправить заявку».

### Что показала система

Снимок DOM (через `page.evaluate`):

```
{
  "result_hidden": true,
  "errors_hidden": false,
  "errors_title": "Заявка не принята",
  "items": [
    "mileage: Пробег от 0 до 500000 км"
  ]
}
```

Сырой ответ API (повторный `curl` для сверки):

```
{"errors":{"mileage":"Пробег от 0 до 500000 км"}}
```

Скриншот: `docs/setup/e2e_mileage_600000_error.png`.

---

## Итог

- Кейс 1 (`mileage=400001`): UI и API показали `decision=review`, LTV = 50%, лимит 0₽. Ожидание выполнено.
- Кейс 2 (`mileage=600000`): UI показал блок «Заявка не принята» с единственной записью
  `mileage: Пробег от 0 до 500000 км`. API вернул ту же ошибку в JSON. Ожидание выполнено.
- Ни один из шагов не упал: все клики/вводы прошли, оба ответа API получены,
  оба скриншота сохранены.