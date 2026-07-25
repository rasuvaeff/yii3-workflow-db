<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransitionsTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WorkflowTransitionsTableName::class)]
final class WorkflowTransitionsTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new WorkflowTransitionsTableName())->value, 'workflow_transitions');
        Assert::same((string) new WorkflowTransitionsTableName(), 'workflow_transitions');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new WorkflowTransitionsTableName('public.workflow_transitions'))->value, 'public.workflow_transitions');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new WorkflowTransitionsTableName('public.workflow_transitions'))->forIndexName(), 'public_workflow_transitions');
        Assert::same((new WorkflowTransitionsTableName('workflow_transitions'))->forIndexName(), 'workflow_transitions');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WorkflowTransitionsTableName($name);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'space' => ['my table'];
        yield 'semicolon injection' => ['t; DROP TABLE users'];
        yield 'dash' => ['my-table'];
        yield 'two dots' => ['a.b.c'];
        // PCRE's $ also matches before a trailing newline — the pattern is
        // anchored with \z so this is rejected
        yield 'trailing newline' => ["workflow_transitions\n"];
    }
}
