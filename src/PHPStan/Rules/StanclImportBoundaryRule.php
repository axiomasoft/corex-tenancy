<?php

declare(strict_types=1);

namespace CoreX\Tenancy\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `Stancl\*` живёт только в corex/tenancy (B-11 §7.3 п.1, D13): boxed-профиль
 * не тянет stancl/tenancy вообще, поэтому любой другой пакет —
 * включая `CoreX\Tenancy\Contracts\*`, которые физически лежат в corex/core —
 * обязан ходить через контракты ядра, а не через типы вендора.
 *
 * Граница проверяется по ПУТИ файла, а не по неймспейсу: неймспейс
 * `CoreX\Tenancy\` принадлежит ДВУМ пакетам (контракты в corex/core,
 * реализации в corex/tenancy, D13), и namespace-проверка пропустила бы
 * ровно тот случай, ради которого инвариант существует — импорт Stancl в
 * boxed-безопасном corex/core. Разрешённые фрагменты пути приходят из
 * `parameters.corexTenancy.stanclAllowedPathFragments` (`extension.neon`),
 * как у контекстных правил modules-lint в corex/modules (P1.13).
 *
 * Правило работает на {@see FileNode} и обходит дерево само: так один проход
 * ловит и `use Stancl\...` (обычный/групповой), и полностью-квалифицированную
 * ссылку `\Stancl\...` без импорта. Комментарии и докблоки не считаются —
 * упоминание `Stancl\*` в докблоке контракта ядра легально и остаётся таким.
 *
 * @implements Rule<FileNode>
 *
 * @internal spec: B-11 §7.3 п.1 (AC-29)
 */
final class StanclImportBoundaryRule implements Rule
{
    /**
     * Корень вендорного неймспейса. Сравнение — по ПЕРВОМУ сегменту имени,
     * а не по префиксу строки: иначе гипотетический `StanclFoo\Bar` дал бы
     * ложное срабатывание.
     */
    private const VENDOR_ROOT = 'Stancl';

    /**
     * @param  list<string>  $allowedPathFragments  фрагменты пути (в POSIX-форме),
     *                                              внутри которых импорт `Stancl\*` легален
     */
    public function __construct(private readonly array $allowedPathFragments) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param  FileNode  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $file = str_replace('\\', '/', $scope->getFile());

        // vendor/ — чужой код, инвариант к нему не адресован (Code Guidance).
        if (str_contains($file, '/vendor/') || $this->insideTenancy($file)) {
            return [];
        }

        $errors = [];
        $reportedLines = [];

        /** @var list<Name> $names */
        $names = (new NodeFinder)->findInstanceOf($node->getNodes(), Name::class);

        foreach ($names as $name) {
            $referenced = $this->stanclName($name);

            if ($referenced === null) {
                continue;
            }

            $line = $name->getStartLine();

            // Одна строка — одна ошибка: `use Stancl\A; use Stancl\B;` в одну
            // строку встречается только в сгенерированном коде, а дублирующие
            // сообщения на одной строке читаются как дефект правила.
            if (isset($reportedLines[$line])) {
                continue;
            }

            $reportedLines[$line] = true;

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Importing "%s" outside corex/tenancy is forbidden (B-11 §7.3 п.1, AC-29) — go through the CoreX\Tenancy\Contracts\* seam so the boxed profile stays free of stancl/tenancy.',
                $referenced,
            ))
                ->identifier('corexTenancy.stanclImportBoundary')
                ->line($line)
                ->build();
        }

        return $errors;
    }

    /**
     * Полное имя, если узел ссылается на `Stancl\*`, иначе `null`.
     *
     * Имя внутри `use`-инструкции уже полное; неквалифицированная ссылка на
     * импортированный класс несёт разрешённое имя в атрибуте `resolvedName`,
     * который проставляет резолвер PHPStan.
     */
    private function stanclName(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        $candidate = $resolved instanceof Name ? $resolved->toString() : $name->toString();

        return explode('\\', ltrim($candidate, '\\'))[0] === self::VENDOR_ROOT ? $candidate : null;
    }

    private function insideTenancy(string $file): bool
    {
        foreach ($this->allowedPathFragments as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
