# Как считается решение approve / review / reject

Документ построен строго по коду в `backend/src/Domain/` и `backend/config/rules.php`.
Никаких домыслов: где в коде чего нет — помечено «нет».

## Участники

`backend/config/rules.php` — справочник бизнес-правил. Для решения используются пороги
`ltv.approve_max = 60.0`, `ltv.review_max = 85.0` и порог пробега
`vehicle.mileage_review_max_km = 400000`; остальные ключи блока `vehicle` (`min_year`,
`max_age_years`, `max_mileage_km`) влияют только на валидацию. Справочник `ltv_by_age`
есть, но в коде пока не читается (отмечено как задача LOAN-12).

`backend/src/Domain/`:

- `AssessmentService.php` — оркестратор, единственная точка входа: `assess(array $payload): array`.
- `ApplicationValidator.php` — нормализует и валидирует вход, бросает `ValidationException`.
- `VinValidator.php` — формальная проверка VIN: длина 17, алфавит `A-Z/0-9`, нет `I/O/Q`.
- `VehicleAge.php` — возраст авто в полных годах (`currentYear − productionYear`).
- `LtvCalculator.php` — LTV = `round(requested_amount / market_value * 100, 2)`,
  защита от деления на ноль и от неположительных сумм.
- `DecisionEngine.php` — решение по LTV и пробегу, хранит пороги из конфига,
  константы `APPROVE/REVIEW/REJECT`.
- `ValidationException.php` — DTO-исключение с массивом `поле => сообщение`.

## Порядок вызовов в `AssessmentService::assess`

1. `ApplicationValidator::validate($payload)`:
   - VIN: `strtoupper(trim(...))` → `VinValidator::isValid`.
   - `year`: `if/elseif` — `min_year`, «не в будущем» (через `VehicleAge::inYears < 0`),
     `max_age_years`.
   - `mileage`: проверка диапазона (см. ниже).
   - `market_value`, `requested_amount`, `term_months` — диапазоны из `rules.amount`
     и `rules.term`.
   - При ошибках — `throw new ValidationException($errors)`. Иначе возвращает
     нормализованный массив `{vin, year, mileage, market_value, requested_amount, term_months}`.
2. `LtvCalculator::calculate($input['requested_amount'], $input['market_value'])`.
3. `DecisionEngine::decide($ltv, $input['mileage'])` — единственная чистая функция решения:
   - `ltv < approveMax` и `mileage <= mileage_review_max_km` → `APPROVE`,
   - `ltv < approveMax` и `mileage > mileage_review_max_km` → `REVIEW`,
   - `ltv <= reviewMax` → `REVIEW`,
   - иначе → `REJECT`.
4. Сборка результата: `vehicle_age = VehicleAge::inYears(year)`,
   `approved_limit = decision === APPROVE ? requested_amount : 0`, плюс `input`.

`VinValidator` и `VehicleAge` сами по себе на ветку approve/review/reject не влияют —
решение выводится из LTV, а пробег только понижает `APPROVE` до `REVIEW` (см. ниже).
`ltv_by_age` из конфига в коде сейчас не используется — нет.

## Куда встало правило «пробег ≤ 400 000 → иначе review»

Правило встало в `DecisionEngine::decide`: до этого движок зависел только от LTV
и ему не хватало данных о пробеге.

- **Файл**: `backend/src/Domain/DecisionEngine.php`, метод `decide`.
- **Где именно**: после ветки по LTV
  (после `if ($ltv <= $this->reviewMax) return self::REVIEW;`,
  до финального `return self::REJECT`) добавить проверку пробега.
  Если `mileage > mileageReviewMaxKm` и текущее решение `APPROVE`,
  принудительно вернуть `REVIEW`. Именно `REVIEW`, не `REJECT`, потому что правило
  звучит «иначе review».
- **Конфиг**: новый ключ в `rules.vehicle` (например, `mileage_review_max_km = 400000`).
  Существующий `vehicle.max_mileage_km = 500000` — это валидационный предел
  («вообще не принимаем»), новое число — мягкий порог решения.
  400 000 и 500 000 — разные величины с разной семантикой, не путать.
- **Сигнатура**: `decide(float $ltv, int $mileage = 0): string`; параметр пробега
  со значением по умолчанию — вызов с одним аргументом сохраняет чистое
  LTV-поведение, правило пробега не срабатывает.
  `LtvCalculator` и валидатор пробег уже отдают — менять их не нужно.
- **Сборка**: в `AssessmentService::assess` перед вызовом решения передать
  второй аргумент: `$this->decisionEngine->decide($ltv, $input['mileage'])`.

### Что уже есть на входе

- `ApplicationValidator::validate` возвращает `mileage` как `int`
  (`(int) ($payload['mileage'] ?? -1)`), число уже нормализовано и провалидировано
  по диапазону 0 … `max_mileage_km`.
- `DecisionEngine` хранит пороги из конфига — значит новый порог тоже подтянется
  через массив `$thresholds` из `rules`.

### Чего не было до реализации (теперь закрыто)

- В `DecisionEngine` не было параметра пробега и соответствующего ключа
  в `$thresholds` — теперь есть (`int $mileage = 0` и `mileage_review_max_km`).
- В `backend/config/rules.php` не было ключа `mileage_review_max_km` — теперь есть
  (400 000), валидационный `vehicle.max_mileage_km = 500000` не тронут.
- Отдельного класса/функции вроде `MileagePolicy` в `backend/src/Domain/` нет
  и после реализации — правило встроено в `DecisionEngine::decide`.
- В `AssessmentService::assess` решение принималось только по LTV без учёта
  пробега — теперь пробег передаётся: `decide($ltv, $input['mileage'])`.

## Что в коде уже проверяется про пробег

Два независимых правила в двух местах. 500 000 — валидационный предел
(«вообще не принимаем»), 400 000 — порог решения («понижаем»), не путать.

**1. Диапазон на входе — `ApplicationValidator::validate`, строки 43–46:**

```php
$mileage = (int) ($payload['mileage'] ?? -1);
if ($mileage < 0 || $mileage > $this->rules['vehicle']['max_mileage_km']) {
    $errors['mileage'] = sprintf('Пробег от 0 до %d км', $this->rules['vehicle']['max_mileage_km']);
}
```

Пробег должен быть целым числом от 0 до `max_mileage_km` (= 500 000). При
нарушении — `ValidationException`, до `LtvCalculator` и `DecisionEngine` дело
не доходит. Отсутствующее поле или null падает здесь же: `?? -1` → -1.
Пустая строка проходит как `0` — на валидации не ловится.

**2. Порог решения — `DecisionEngine::decide(float $ltv, int $mileage = 0)`:**

при пробеге свыше `vehicle.mileage_review_max_km` (= 400 000) решение `APPROVE`
по LTV понижается до `REVIEW`; ветки `REVIEW` и `REJECT` по LTV пробегом
не меняются. Порог приходит в конструктор движка с ключом `mileage_review_max_km`
(прокидывает `AppFactory`); без ключа правило неактивно — движок решает по
чистому LTV. Пробег передаёт `AssessmentService::assess`.

В `LtvCalculator`, `VinValidator`, `VehicleAge` пробег не учитывается.
