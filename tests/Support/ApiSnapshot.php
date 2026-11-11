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
        $parent = $class->getParentClass();
        // An @internal parent is an implementation detail: its members are listed under the class that inherits them.
        $stableParent = $parent !== false && !self::internal($parent->getDocComment()) ? $parent : null;
        if ($stableParent !== null) {
            $line .= ' extends ' . $stableParent->getName();
        }
        $interfaces = $class->isInterface() ? [] : array_diff($class->getInterfaceNames(), $stableParent?->getInterfaceNames() ?? []);
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
            if (!self::listed($class, $constant) || self::internal($constant->getDocComment())) {
                continue;
            }
            $lines[] = 'const ' . self::visibility($constant) . ' ' . $constant->getName();
        }
        foreach ($class->getProperties() as $property) {
            if (!self::listed($class, $property) || self::internal($property->getDocComment())) {
                continue;
            }
            $lines[] = 'property ' . self::visibility($property)
                . ($property->isStatic() ? ' static' : '')
                . ($property->isReadOnly() ? ' readonly' : '')
                . ($property->getType() !== null ? ' ' . self::type((string) $property->getType(), $class) : '')
                . ' $' . $property->getName();
        }
        foreach ($class->getMethods() as $method) {
            if (!self::listed($class, $method) || self::internal($method->getDocComment())) {
                continue;
            }
            $params = array_map(static function (\ReflectionParameter $p) use ($class): string {
                $text = ($p->getType() !== null ? self::type((string) $p->getType(), $class) . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
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
                . ($method->hasReturnType() ? ': ' . self::type((string) $method->getReturnType(), $class) : '');
        }
        sort($lines);
        return $lines;
    }

    /**
     * Whether a member belongs in the snapshot of this class: it is reachable (not private, and not protected
     * in a final class) and is declared here or in an @internal ancestor, which is never listed on its own.
     *
     * @param \ReflectionClass<object> $class
     */
    /** PHP 8.5 reflects `self` as the class name; render both the same so the snapshot does not depend on the PHP version. */
    private static function type(string $type, \ReflectionClass $class): string
    {
        $name = preg_quote($class->getName(), '/');
        return (string) preg_replace('/(?<![\\w\\\\])' . $name . '(?![\\w\\\\])/', 'self', $type);
    }

    private static function listed(\ReflectionClass $class, \ReflectionClassConstant|\ReflectionProperty|\ReflectionMethod $member): bool
    {
        if ($member->isPrivate() || ($member->isProtected() && $class->isFinal())) {
            return false;
        }
        $declaring = $member->getDeclaringClass();
        return $declaring->getName() === $class->getName() || self::internal($declaring->getDocComment());
    }

    private static function visibility(\ReflectionClassConstant|\ReflectionProperty|\ReflectionMethod $member): string
    {
        return $member->isPublic() ? 'public' : 'protected';
    }
}
