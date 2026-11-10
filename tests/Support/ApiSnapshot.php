<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Support;

/**
 * Describes the public API (public and protected members of every class not tagged @internal)
 * as stable text, so an accidental or breaking change shows up as a diff in review.
 */
final class ApiSnapshot
{
    public static function render(string $srcDir, string $namespace = 'Dmorenof\\SdkRuntime'): string
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($srcDir, '/')) + 1, -4);
            $name = $namespace . '\\' . str_replace('/', '\\', $relative);
            if (!class_exists($name) && !interface_exists($name)) {
                throw new \LogicException("Cannot load $name from $relative.php");
            }
            $classes[] = new \ReflectionClass($name);
        }
        usort($classes, static fn (\ReflectionClass $a, \ReflectionClass $b): int => strcmp($a->getName(), $b->getName()));

        $out = [];
        foreach ($classes as $class) {
            if (self::internal($class->getDocComment())) {
                continue;
            }
            $out[] = self::header($class);
            foreach (self::members($class) as $line) {
                $out[] = '    ' . $line;
            }
            $out[] = '';
        }
        return implode("\n", $out);
    }

    private static function internal(string|false $doc): bool
    {
        return $doc !== false && str_contains($doc, '@internal');
    }

    /** @param \ReflectionClass<object> $class */
    private static function header(\ReflectionClass $class): string
    {
        $kind = $class->isInterface() ? 'interface' : 'class';
        $modifiers = ($class->isAbstract() && !$class->isInterface() ? 'abstract ' : '')
            . ($class->isFinal() ? 'final ' : '')
            . ($class->isReadOnly() ? 'readonly ' : '');
        $line = $modifiers . $kind . ' ' . $class->getName();
        if ($class->getParentClass() !== false) {
            $line .= ' extends ' . $class->getParentClass()->getName();
        }
        $interfaces = array_diff($class->getInterfaceNames(), $class->getParentClass() !== false ? $class->getParentClass()->getInterfaceNames() : []);
        if ($class->isInterface()) {
            $interfaces = [];
        }
        if ($interfaces !== []) {
            sort($interfaces);
            $line .= ' implements ' . implode(', ', $interfaces);
        }
        return $line;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return list<string>
     */
    private static function members(\ReflectionClass $class): array
    {
        $lines = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->isPrivate() || $constant->getDeclaringClass()->getName() !== $class->getName() || self::internal($constant->getDocComment())) {
                continue;
            }
            $lines[] = 'const ' . self::visibility($constant) . ' ' . $constant->getName();
        }
        foreach ($class->getProperties() as $property) {
            if ($property->isPrivate() || $property->getDeclaringClass()->getName() !== $class->getName() || self::internal($property->getDocComment())) {
                continue;
            }
            $lines[] = 'property ' . self::visibility($property)
                . ($property->isStatic() ? ' static' : '')
                . ($property->isReadOnly() ? ' readonly' : '')
                . ($property->getType() !== null ? ' ' . $property->getType() : '')
                . ' $' . $property->getName();
        }
        foreach ($class->getMethods() as $method) {
            if ($method->isPrivate() || $method->getDeclaringClass()->getName() !== $class->getName() || self::internal($method->getDocComment())) {
                continue;
            }
            $params = array_map(static function (\ReflectionParameter $p): string {
                $text = ($p->getType() !== null ? $p->getType() . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
                if ($p->isDefaultValueAvailable()) {
                    $text .= ' = ' . ($p->isDefaultValueConstant() ? (string) $p->getDefaultValueConstantName()
                        : (string) preg_replace('/\s+/', ' ', var_export($p->getDefaultValue(), true)));
                }
                return $text;
            }, $method->getParameters());
            $lines[] = 'method ' . self::visibility($method)
                . ($method->isStatic() ? ' static' : '')
                . ($method->isFinal() ? ' final' : '')
                . ($method->isAbstract() && !$class->isInterface() ? ' abstract' : '')
                . ' ' . $method->getName() . '(' . implode(', ', $params) . ')'
                . ($method->hasReturnType() ? ': ' . $method->getReturnType() : '');
        }
        sort($lines);
        return $lines;
    }

    private static function visibility(\ReflectionClassConstant|\ReflectionProperty|\ReflectionMethod $member): string
    {
        return $member->isPublic() ? 'public' : 'protected';
    }
}
