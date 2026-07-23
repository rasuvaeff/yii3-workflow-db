# rasuvaeff/yii3-workflow-db

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-workflow-db?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-workflow-db?label=downloads)
![Build](https://github.com/rasuvaeff/yii3-workflow-db/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/yii3-workflow-db/actions/workflows/static-analysis.yml/badge.svg)
![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-workflow-db?label=license)

Бэкенд хранения для [`rasuvaeff/yii3-workflow`](https://github.com/rasuvaeff/yii3-workflow):
история переходов в `yiisoft/db` и защита от повторов, ставшая ограничением БД,
а не проверкой в коде.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный API-справочник,
> созданный для LLM.

[English version](README.md)

## Что делает

- **Биндит `TransitionLog`.** Ядро сознательно оставляет интерфейс несвязанным;
  установка этого пакета включает аудит и идемпотентность.
- **Пишет по строке на каждый закоммиченный переход** — `symfony/workflow`
  хранит только текущий marking, истории у него нет.
- **Делает идемпотентность инвариантом БД.** Уникальный индекс по
  `(workflow, subject_id, idempotency_key)` решает гонку, которую не может
  решить проверка «сначала посмотрел, потом записал»: у проигравшего INSERT
  падает, а ядро трактует это как повтор.
- **Поставляет миграцию** и команду `workflow:transitions:prune`, потому что
  таблица, которую никто не читает обратно, растёт вечно.

## Требования

- PHP 8.3 / 8.4 / 8.5
- `rasuvaeff/yii3-workflow` ^1.0
- `yiisoft/db` ^2.0, `yiisoft/db-migration` ^2.0
- `symfony/console` (для команды очистки), `psr/clock`

## Установка

```bash
composer require rasuvaeff/yii3-workflow-db
```

`yiisoft/config` сам свяжет `TransitionLog` с `DbTransitionLog`. Затем миграция:

```bash
./yii migrate:up --path=@vendor/rasuvaeff/yii3-workflow-db/migrations
```

## Конфигурация

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-workflow-db' => [
        'table' => 'workflow_transitions',
        'retentionDays' => 90,   // значение по умолчанию для workflow:transitions:prune
    ],
];
```

## Схема

| Колонка | Тип | Заметка |
|---|---|---|
| `id` | bigint PK | порядок вставки = хронология |
| `workflow` | string(64) | имя автомата |
| `subject_id` | string(128) | из `SubjectIdentity::workflowSubjectId()` |
| `transition` | string(64) | применённый переход |
| `from_place` / `to_place` | string(512) | для сетей Петри — через запятую |
| `at` | string(30) | ATOM, нормализованный в UTC, поэтому сортировка лексикографическая на любом драйвере |
| `idempotency_key` | string(128), nullable | NULL повторяется свободно, значение — уникально в пределах субъекта |

Индексы: `(workflow, subject_id, id)` для истории субъекта, `(at)` для очистки и
отчётов, и **уникальный** `(workflow, subject_id, idempotency_key)`.

Метки времени переводятся в UTC перед записью: при плавающем смещении
(переход на летнее время, app-серверы в разных часовых поясах) строковый
порядок, на который опирается очистка, сломался бы.

Миграция подстраивает уникальный индекс под драйвер. MySQL, PostgreSQL и SQLite
считают NULL в уникальном индексе различными — обычного индекса достаточно,
чтобы строки без ключа повторялись свободно; MSSQL и Oracle считают их равными,
поэтому там индекс покрывает только строки с ключом (filtered index в MSSQL,
function-based в Oracle). Тесты гоняются на SQLite — ветки MSSQL/Oracle
считайте best-effort и проверьте в своём окружении.

## Использование

На стороне вызова ничего не меняется — тот же API ядра, только теперь он
записывает и проверяет:

```php
$workflow = $registry->get('order');

if (!$workflow->applyOnce($order, 'ship', idempotencyKey: $requestId)) {
    return;   // повтор: так решила либо проверка, либо уникальный индекс
}
```

Чтение истории за пределами интерфейса `TransitionLog`:

```php
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;

$log->forSubject('order', $order->getId());   // хронологически
$log->latest('order', limit: 50, offset: 0);  // свежие первыми, для админки
$log->count('order');                         // всего строк, для пейджера
$log->prune(new DateTimeImmutable('-90 days'));
```

### Транзакции

Строка аудита пишется внутри `apply()`, то есть **после** того, как объект уже
изменён в памяти. Если уникальный индекс отклонит запись, `applyOnce()` вернёт
`false`, а объект в памяти уже «уехал». Оборачивайте вызов и сохранение сущности
в одну транзакцию — или выбрасывайте объект, — чтобы проигранная гонка не попала
в хранилище:

```php
$this->db->transaction(function () use ($order, $requestId): void {
    if (!$this->registry->get('order')->applyOnce($order, 'ship', $requestId)) {
        return;
    }

    $this->orders->save($order);
});
```

`WorkflowTransaction` сводит рецепт к одному вызову — замыкание `then`
выполняется в той же транзакции и только если переход действительно применился:

```php
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;

final readonly class ShipOrderHandler
{
    public function __construct(
        private WorkflowRegistry $registry,
        private WorkflowTransaction $transaction,
    ) {}

    public function handle(Order $order, string $requestId): void
    {
        $this->transaction->applyOnce(
            $this->registry->get('order'),
            $order,
            'ship',
            $requestId,
            then: fn() => $this->orders->save($order),
        );
    }
}
```

Упавший `save()` откатывает и строку аудита, поэтому ключ не сжигается и повтор
может пройти.

### Очистка

```bash
./yii workflow:transitions:prune                      # настроенный срок хранения
./yii workflow:transitions:prune --older-than=30
./yii workflow:transitions:prune --older-than=30 --dry-run
```

Очистка стирает и idempotency-ключи: запрос, повторённый с ключом старше окна
хранения, будет применён заново. Держите окно длиннее самого долгого
правдоподобного повтора (ретраи клиента, redelivery очередей).

## Безопасность

Строки пишутся из событий workflow, а не из пользовательского ввода; все запросы
параметризованные, через `yiisoft/db`. История — источник аудита: не давайте
пользователю БД приложения право `DELETE` на эту таблицу, если он не запускает
команду очистки, и помните, что `subject_id` может идентифицировать человека —
срок хранения это решение про приватность, а не только про место на диске.

## Примеры

См. [`examples/`](examples/) — исполняемый скрипт на in-memory SQLite.

## Разработка

```bash
make install
make build       # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-integration
make mutation    # нужен pcov; Makefile его ставит
```

Unit- и интеграционные тесты идут на in-memory SQLite, поэтому для базы и
миграций сервер не нужен. `make build` запускает unit-набор; для SQLite
интеграционных тестов используйте `make test-integration`. PHP и Composer на
хосте нет — все цели выполняются в Docker-образе `composer:2`.

## Лицензия

BSD-3-Clause. См. [`LICENSE.md`](LICENSE.md).
