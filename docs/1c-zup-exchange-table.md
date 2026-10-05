# Промежуточная таблица обмена с 1С:ЗУП

Для выгрузки готовых заявок на сверхурочную работу и работу в выходной день предлагается использовать таблицу `OvertimeRequestExport` со следующими полями.

| Поле | Тип SQL Server | NULL | Назначение |
| --- | --- | --- | --- |
| `RequestId` | `BIGINT` | нет | Номер (ID) заявки в Bitrix24. Рекомендуется сделать первичным ключом, чтобы одна заявка не попадала в таблицу повторно. |
| `RequestType` | `NVARCHAR(32)` | нет | Тип заявки: стабильный код `OVERTIME` (сверхурочная работа) или `WEEKEND` (работа в выходной день). |
| `EmployeeGuid` | `UNIQUEIDENTIFIER` | нет | GUID сотрудника, по которому сотрудник сопоставляется в 1С:ЗУП. |
| `WorkStartAt` | `DATETIME2(0)` | нет | Дата и время начала работ. |
| `WorkEndAt` | `DATETIME2(0)` | нет | Дата и время окончания работ. |
| `TotalHours` | `DECIMAL(9,2)` | нет | Общее количество часов по заявке. |
| `GroupRequestId` | `BIGINT` | да | ID групповой заявки. `NULL` означает, что заявка не является групповой. Отдельный признак групповой заявки не нужен. |
| `IsLinkedRequest` | `BIT` | нет | `1`, если у заявки есть связанные заявки; иначе `0`. |
| `IsProcessed` | `BIT` | нет | Признак обработки записи в 1С: `0` — запись еще нужно загрузить, `1` — запись уже обработана и повторно загружать ее не нужно. |

Рекомендуемое определение таблицы:

```sql
CREATE TABLE dbo.OvertimeRequestExport
(
    RequestId        BIGINT           NOT NULL,
    RequestType      NVARCHAR(32)     NOT NULL,
    EmployeeGuid     UNIQUEIDENTIFIER NOT NULL,
    WorkStartAt      DATETIME2(0)     NOT NULL,
    WorkEndAt        DATETIME2(0)     NOT NULL,
    TotalHours       DECIMAL(9,2)     NOT NULL,
    GroupRequestId   BIGINT           NULL,
    IsLinkedRequest  BIT              NOT NULL
        CONSTRAINT DF_OvertimeRequestExport_IsLinkedRequest DEFAULT (0),
    IsProcessed      BIT              NOT NULL
        CONSTRAINT DF_OvertimeRequestExport_IsProcessed DEFAULT (0),

    CONSTRAINT PK_OvertimeRequestExport PRIMARY KEY (RequestId),
    CONSTRAINT CK_OvertimeRequestExport_RequestType
        CHECK (RequestType IN (N'OVERTIME', N'WEEKEND')),
    CONSTRAINT CK_OvertimeRequestExport_TotalHours
        CHECK (TotalHours >= 0),
    CONSTRAINT CK_OvertimeRequestExport_WorkPeriod
        CHECK (WorkEndAt >= WorkStartAt)
);

CREATE INDEX IX_OvertimeRequestExport_Unprocessed
    ON dbo.OvertimeRequestExport (IsProcessed, RequestId);
```

В экспорт должны попадать только заявки со статусом «Выполнена» и только с типами `OVERTIME` и `WEEKEND`; заявки на дежурство в этот обмен не включаются. После успешной загрузки строки 1С должна устанавливать `IsProcessed = 1`. Значение следует менять только после завершения обработки записи, чтобы при ошибке ее можно было прочитать повторно.

