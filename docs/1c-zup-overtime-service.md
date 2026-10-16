# Веб-сервис учета сверхурочных часов 1С ЗУП

При создании заявки Bitrix24 получает из пользовательского поля `UF_1C_GUID` GUID сотрудника и передает его вместе с текущей датой в веб-сервис 1С ЗУП. Сервис должен вернуть суммарное количество уже оформленных часов сверхурочной работы с начала года по переданную дату.

## Пример запроса

```http
POST /hs/overtime/v1/annual-hours HTTP/1.1
Host: zup.example.local
Content-Type: application/json; charset=UTF-8
Accept: application/json

{
  "employeeGuid": "6f9619ff-8b86-d011-b42d-00cf4fc964ff",
  "currentDate": "2026-10-06"
}
```

Аналогичный запрос через `curl`:

```bash
curl --request POST 'https://zup.example.local/hs/overtime/v1/annual-hours' \
  --header 'Content-Type: application/json; charset=UTF-8' \
  --header 'Accept: application/json' \
  --data '{"employeeGuid":"6f9619ff-8b86-d011-b42d-00cf4fc964ff","currentDate":"2026-10-06"}'
```

## Пример успешного ответа

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=UTF-8

{
  "employeeGuid": "6f9619ff-8b86-d011-b42d-00cf4fc964ff",
  "asOfDate": "2026-10-06",
  "year": 2026,
  "overtimeHours": 42.5
}
```

Поле `overtimeHours` обязательно, имеет числовой тип, не может быть отрицательным и содержит итог за календарный год. Остальные поля ответа предназначены для диагностики и сверки.

## Подключение после реализации сервиса

Сейчас `ZUP_OVERTIME_SERVICE_ENABLED` имеет значение `false`, поэтому внешний HTTP-запрос не выполняется, а проверка использует `ZUP_OVERTIME_STUB_HOURS` (по умолчанию `0`). Для подключения сервиса необходимо указать HTTPS-адрес в `ZUP_OVERTIME_SERVICE_URL`, при необходимости изменить тайм-аут и включить `ZUP_OVERTIME_SERVICE_ENABLED`. Реестр часов сверхурочки (инфоблок 392) в расчете больше не используется.
