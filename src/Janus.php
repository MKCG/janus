<?php

namespace MKCG\Janus;

interface ContentAnalyzer
{
    public function extract(array $tokens) : \Generator;
}

abstract class PhpComponent
{
    public $name;

    public $tokenStartPos;

    public $tokenEndPos;

    public function toArray() : array
    {
        $data = [];

        foreach (array_keys(get_object_vars($this)) as $name) {
            if ($this->$name instanceof PhpComponent) {
                $data[$name] = $this->$name->toArray();
            } elseif (is_array($this->$name)) {
                $data[$name] = array_map(function ($value) {
                    return $value instanceof PhpComponent
                        ? $value->toArray()
                        : $value;
                }, $this->$name);
            } else {
                $data[$name] = $this->$name;
            }
        }

        return $data;
    }
}

class NamespaceComponent extends PhpComponent
{
    public $uses = [];

    public $classes = [];
}

class InterfaceComponent extends PhpComponent
{
    public $namespace;

    public $methods = [];
}

class ClassComponent extends InterfaceComponent
{
    public $interfaces = [];

    public $extends;

    public $traits = [];

    public $traitRenamedMethods = [];

    public $isAnonym = false;

    public $isAbstract = false;

    public $isFinal = false;

    public $constants = [];

    public $properties = [];
}

class FunctionComponent extends PhpComponent
{
    public $params = [];

    public $returnType;

    public $visibility;

    public $isStatic = false;

    public $isAnonym = false;

    public $isAbstract = false;

    public $isFinal = false;

    public $callMethods = [];

    public $instantiated = [];
}

class VariableComponent extends PhpComponent
{
    public $type;

    public $hasDefaultValue = false;

    public $defaultValue;
}

class ParamComponent extends VariableComponent
{
    public $isVariadic = false;
}

class PropertyComponent extends VariableComponent
{
    public $isStatic = false;

    public $visibility;
}

class Janus implements ContentAnalyzer
{
    private $fileAnalyzer;

    public function __construct(FileAnalyzer $fileAnalyzer)
    {
        $this->fileAnalyzer     = $fileAnalyzer;
    }

    public function analyze(string $path)
    {
        $contents = file_get_contents($path);
        $tokens = token_get_all($contents);

        foreach ($this->extract($tokens) as $component) {
            yield $component;
        }
    }

    public function extract(array $tokens) : \Generator
    {
        yield from $this->fileAnalyzer->extract($tokens);
    }
}

class FileAnalyzer implements ContentAnalyzer
{
    private $classAnalyzer;
    private $functionAnalyzer;

    public function __construct(ClassAnalyzer $classAnalyzer, FunctionAnalyzer $functionAnalyzer)
    {
        $this->classAnalyzer    = $classAnalyzer;
        $this->functionAnalyzer = $functionAnalyzer;
    }

    public function extract(array $tokens) : \Generator
    {
        foreach ($this->extractNamespaces($tokens) as $component) {
            yield $component;
        }
    }

    private function extractNamespaces(array $tokens)
    {
        $namespaces = [];

        $isNamespace = false;
        $isNested = false;
        $nestedLevel = 0;
        $currentNamespace = '';
        $startedPos = null;

        foreach ($tokens as $pos => $token) {
            if (!$isNamespace && !$isNested) {
                if (is_array($token) && $token[0] === T_NAMESPACE) {
                    $isNamespace = true;
                    $startedPos = $pos;
                }
            } elseif ($isNamespace && is_array($token) && in_array($token[0], [T_NS_SEPARATOR, T_STRING], true)) {
                $currentNamespace .= $token[1];
            } elseif ($isNamespace && $token === ';') {
                $namespaceComponent = new NamespaceComponent();
                $namespaceComponent->name = $currentNamespace;
                $namespaceComponent->tokenStartPos = $startedPos;
                $namespaces[] = $namespaceComponent;

                $isNamespace = false;
                $startedPos = null;
                $currentNamespace = '';
                $isNested = false;
                $nestedLevel = 0;
            } elseif (($isNamespace || $isNested) && $token === '{') {
                $isNamespace = false;
                $isNested = true;
                $nestedLevel++;
            } elseif ($isNested && $token === '}') {
                $nestedLevel--;

                if ($nestedLevel === 0) {
                    $namespaceComponent = new NamespaceComponent();
                    $namespaceComponent->name = $currentNamespace;
                    $namespaceComponent->tokenStartPos = $startedPos;
                    $namespaceComponent->tokenEndPos = $pos;
                    $namespaces[] = $namespaceComponent;

                    $startedPos = null;
                    $currentNamespace = '';
                    $isNested = false;
                }
            }
        }

        if (isset($namespaces[0]) && !isset($namespaces[1]) && $namespaces[0]->tokenEndPos === null) {
            $namespaces[0]->tokenEndPos = $pos;
        } elseif (isset($namespaces[1])) {
            for ($i = count($namespaces) - 1; isset($namespaces[$i]); $i--) {
                if ($namespaces[$i]->tokenEndPos !== null) {
                    continue;
                }

                $namespaces[$i]->tokenEndPos = !isset($namespaces[$i + 1])
                    ? $pos
                    : $namespaces[$i + 1]->tokenStartPos - 1;
            }
        } elseif (!isset($namespaces[0])) {
            $namespaceComponent = new NamespaceComponent();
            $namespaceComponent->name = '';
            $namespaceComponent->tokenStartPos = 0;
            $namespaceComponent->tokenEndPos = $pos;

            $namespaces[] = $namespaceComponent;
        }

        $namespaces = array_map(function (NamespaceComponent $namespace) use ($tokens) {
            $namespaceTokens = array_slice($tokens, $namespace->tokenStartPos, $namespace->tokenEndPos - $namespace->tokenStartPos, true);
            $namespace->uses = $this->extractUsedClasses($namespaceTokens);

            foreach ($this->classAnalyzer->extract($namespaceTokens) as $classComponent) {
                $namespace->classes[] = $classComponent;
            }

            return $namespace;
        }, $namespaces);

        return $namespaces;
    }

    private function extractUsedClasses(array $tokens)
    {
        $classes = [];
        $currentClass = '';
        $currentAlias = '';
        $multiplePrefix = '';

        $isUse = false;
        $isMultiple = false;
        $isAlias = false;

        // Used to prevent anonym function "use" token to be detected as namespace use
        $isFunction = false;

        foreach ($tokens as $pos => $token) {
            if (!$isUse) {
                if (!$isFunction && is_array($token) && $token[0] === T_FUNCTION) {
                    $isFunction = true;
                    continue;
                }

                if ($isFunction && $token === ';') {
                    $isFunction = false;
                    continue;
                }

                if (!$isFunction && is_array($token) && $token[0] === T_USE) {
                    $isUse = true;
                    continue;
                }
            }

            if ($isUse && in_array($token, [';', '}'])) {
                if (!$isAlias) {
                    $classes[$currentClass] = $currentClass;
                } else {
                    $classes[$currentAlias] = $currentClass;
                }

                $isUse = false;
                $isAlias = false;
                $isMultiple = false;
                $currentClass = '';
                $currentAlias = '';
                $multiplePrefix = '';
            } elseif ($isUse && $token === '{') {
                $isMultiple = true;
                $multiplePrefix = $currentClass;
                $currentClass = '';
            } elseif ($isUse && $isMultiple && $token === ',') {
                $currentClass = $multiplePrefix . $currentClass;

                if (!$isAlias) {
                    $classes[$currentClass] = $currentClass;
                } else {
                    $classes[$currentAlias] = $currentClass;
                }

                $isAlias = false;
                $currentClass = '';
                $currentAlias = '';
            }

            if (!$isUse || !is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_NS_SEPARATOR, T_STRING], true)) {
                if ($isAlias) {
                    $currentAlias .= $token[1];
                } else {
                    $currentClass .= $token[1];
                }
            } elseif ($token[0] === T_AS) {
                $isAlias = true;
            }
        }

        return $classes;
    }
}

class ClassAnalyzer implements ContentAnalyzer
{
    private $functionAnalyzer;

    public function __construct(FunctionAnalyzer $functionAnalyzer)
    {
        $this->functionAnalyzer = $functionAnalyzer;
    }

    public function extract(array $tokens) : \Generator
    {
        $currentClassTokens = [];
        $isClass            = false;
        $isAbstract         = false;
        $isFinal            = false;
        $nestedLevel        = 0;

        $startedPos = -1;
        $endedPos = -1;

        foreach ($tokens as $pos => $token) {
            if (!isset($currentClassTokens[0]) && is_array($token) && in_array($token[0], [T_ABSTRACT, T_FINAL, T_CLASS], true)) {
                $followingTokens = array_slice($tokens, $pos + 1, 4);
                $tokenTypes = array_column($followingTokens, 0);

                $isClass = $token[0] === T_CLASS || in_array(T_CLASS, $tokenTypes, true);

                if (!$isClass) {
                    continue;
                }

                $startedPos = $pos;
                $isAbstract = $token[0] === T_ABSTRACT || in_array(T_ABSTRACT, $tokenTypes, true);
                $isFinal    = $token[0] === T_FINAL || in_array(T_FINAL, $tokenTypes, true);
            } elseif (!$isClass) {
                continue;
            }

            $currentClassTokens[] = $token;

            if ($token === '{') {
                $nestedLevel++;
            } elseif ($token === '}') {
                $nestedLevel--;

                if ($nestedLevel === 0) {
                    $endedPos = $pos;
                    $classComponent = $this->createClass($currentClassTokens);
                    $classComponent->isAbstract = $isAbstract;
                    $classComponent->isFinal    = $isFinal;

                    $classComponent->tokenStartPos = $startedPos;
                    $classComponent->tokenEndPos   = $endedPos;

                    array_walk($classComponent->methods, function($method) use ($startedPos) {
                        $method->tokenStartPos += $startedPos;
                        $method->tokenEndPos += $startedPos;
                    });

                    yield $classComponent;

                    $currentClassTokens = [];
                    $isClass    = false;
                    $isFinal    = false;
                    $isAbstract = false;
                }
            }
        }
    }

    private function createClass(array $tokens)
    {
        $component = new ClassComponent();

        $component->name        = $this->extractName($tokens);
        $component->interfaces  = $this->extractInterfaces($tokens);
        $component->extends     = $this->extractExtends($tokens);

        $innerTokens = $tokens;

        foreach ($tokens as $pos => $token) {
            if ($token === '{') {
                $innerTokens = array_slice($tokens, $pos + 1, count($tokens) - $pos - 2);
                break;
            }
        }

        foreach ($this->extractMethods($innerTokens) as $method) {
            $method->tokenStartPos += $pos + 1;
            $method->tokenEndPos += $pos + 1;
            $component->methods[] = $method;
        }

        $component->properties  = $this->extractProperties($innerTokens);

        return $component;
    }

    private function extractName(array $tokens)
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        throw new \Exception('class name not found');
    }

    private function extractExtends(array $tokens)
    {
        $extended = '';
        $extendsFound = false;

        foreach ($tokens as $token) {
            if ($token === '{' || is_array($token) && $token[0] === T_IMPLEMENTS) {
                break;
            }

            if (!$extendsFound && is_array($token) && $token[0] === T_EXTENDS) {
                $extendsFound = true;
                continue;
            }

            if (!$extendsFound || !is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NS_SEPARATOR], true)) {
                $extended .= $token[1];
                continue;
            }
        }

        return $extended;
    }

    private function extractInterfaces(array $tokens)
    {
        $interfaces = [];
        $currInterface = '';

        $interfaceFound = false;

        foreach ($tokens as $token) {
            if ($interfaceFound && $token === '{') {
                if ($currInterface[0] !== '') {
                    $interfaces[] = $currInterface;
                }

                break;
            }

            if ($interfaceFound && $token === ',' && $currInterface !== '') {
                $interfaces[] = $currInterface;
                $currInterface = '';
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_IMPLEMENTS) {
                $interfaceFound = true;
            } elseif ($interfaceFound && in_array($token[0], [T_STRING, T_NS_SEPARATOR], true)) {
                $currInterface .= $token[1];
            }
        }

        return $interfaces;
    }

    private function extractMethods(array $tokens)
    {
        $methods = [];

        foreach ($this->functionAnalyzer->extract($tokens) as $component) {
            $methods[] = $component;
        }

        return $methods;
    }

    private function extractProperties(array $tokens)
    {
        $properties = [];

        $isFunction = false;
        $nestedLevel = 0;

        $currentProperty = null;

        $isAssignment = false;
        $assignmentTokens = [];

        foreach ($tokens as $pos => $token) {
            if ($isFunction) {
                if ($nestedLevel === 0 && $token === ';') {
                    $isFunction = false;
                } elseif ($token === '{') {
                    $nestedLevel++;
                } elseif ($token === '}') {
                    $nestedLevel--;
                    $isFunction = $nestedLevel !== 0;
                }
            } elseif (is_array($token) && $token[0] === T_FUNCTION) {
                $isFunction = true;
            }

            if ($isFunction) {
                continue;
            }

            if ($currentProperty === null && is_array($token) && in_array($token[0], [T_VARIABLE], true)) {
                $currentProperty = new PropertyComponent();
                $currentProperty->name = substr($token[1], 1);

                $sliceBegin = max($pos - 4, 0);
                $sliceLength = max($pos - $sliceBegin, 0);

                $previousTokens = array_slice($tokens, $sliceBegin, $sliceLength, true);
                $previousTokens = array_filter($previousTokens, 'is_array');

                array_walk($previousTokens, function ($token) use ($currentProperty) {
                    switch ($token[0]) {
                        case T_STATIC:
                            $currentProperty->isStatic = true;
                            break;
                        case T_PUBLIC:
                            $currentProperty->visibility = 'public';
                            break;
                        case T_PROTECTED:
                            $currentProperty->visibility = 'protected';
                            break;
                        case T_PRIVATE:
                            $currentProperty->visibility = 'private';
                            break;
                    }
                });

                continue;
            }

            if ($currentProperty === null) {
                continue;
            }

            if ($token === ';') {
                if ($isAssignment) {
                    ParamAnalyzer::assignDefaultValue($currentProperty, $assignmentTokens);
                }

                $properties[] = $currentProperty;
                $currentProperty = null;
                $isAssignment = false;
                $assignmentTokens = [];
            } elseif ($token === '=') {
                $isAssignment = true;
            } elseif ($isAssignment && (!is_array($token) || $token[0] !== T_WHITESPACE)) {
                $assignmentTokens[] = $token;
            }
        }

        return $properties;
    }
}

class FunctionAnalyzer implements ContentAnalyzer
{
    private $paramAnalyzer;

    public function __construct(ParamAnalyzer $paramAnalyzer)
    {
        $this->paramAnalyzer = $paramAnalyzer;
    }

    public function extract(array $tokens) : \Generator
    {
        $isMethod       = false;
        $isAbstract     = false;
        $isStatic       = false;
        $isFinal        = false;
        $isAnonym       = false;
        $visibility     = 'public';
        $nestedLevel    = 0;
        $startedPos;

        $currFunctionTokens = [];

        foreach ($tokens as $pos => $token) {
            if (!$isMethod
                && is_array($token)
                && in_array($token[0], [T_FINAL, T_ABSTRACT, T_FUNCTION, T_PUBLIC, T_PROTECTED, T_PRIVATE], true)
            ) {
                $followingTypes = [];

                foreach (array_slice($tokens, $pos + 1) as $followingToken) {
                    if (!is_array($followingToken)) {
                        break;
                    }

                    if (in_array($followingToken[0], [T_WHITESPACE], true)) {
                        continue;
                    }

                    if (!in_array($followingToken[0], [T_FINAL, T_ABSTRACT, T_FUNCTION, T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                        break;
                    }

                    $followingTypes[] = $followingToken[0];
                }

                $isMethod = $token[0] === T_FUNCTION || in_array(T_FUNCTION, $followingTypes, true);

                if (!$isMethod) {
                    continue;
                }

                $startedPos = $pos;
                $isAbstract = $token[0] === T_ABSTRACT || in_array(T_ABSTRACT, $followingTypes, true);
                $isStatic   = $token[0] === T_STATIC   || in_array(T_STATIC, $followingTypes, true);
                $isFinal    = $token[0] === T_FINAL    || in_array(T_FINAL, $followingTypes, true);

                $visibilityTokens = in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED], true)
                    ? [$token[0]]
                    : array_intersect([T_PUBLIC, T_PRIVATE, T_PROTECTED], $followingTypes);
                $visibilityTokens = array_values($visibilityTokens);

                if ($visibilityTokens !== []) {
                    switch ($visibilityTokens[0]) {
                        case T_PUBLIC:
                            $visibility = 'public';
                            break;
                        case T_PROTECTED:
                            $visibility = 'protected';
                            break;
                        case T_PRIVATE:
                            $visibility = 'private';
                            break;
                    }
                } else {
                    $visibility = 'public';
                }
            }

            if ($isMethod) {
                $currFunctionTokens[] = $token;
            }

            if ($token === '{') {
                $nestedLevel++;
            } elseif ($token === '}') {
                $nestedLevel--;

                if ($nestedLevel === 0) {
                    $functionComponent = $this->createFunction($currFunctionTokens);
                    $functionComponent->isStatic = $isStatic;
                    $functionComponent->isFinal  = $isFinal;
                    $functionComponent->isAbstract = $isAbstract;
                    $functionComponent->visibility = $visibility;
                    $functionComponent->tokenStartPos = $startedPos;
                    $functionComponent->tokenEndPos = $pos;

                    yield $functionComponent;

                    $isMethod       = false;
                    $isAbstract     = false;
                    $isStatic       = false;
                    $isFinal        = false;
                    $isAnonym       = false;
                    $visibility     = 'public';
                    $nestedLevel    = 0;
                    $startedPos = null;

                    $currFunctionTokens = [];
                }
            }
        }
    }

    private function createFunction(array $tokens)
    {
        $innerTokens = $tokens;
        $headerTokens = $tokens;

        foreach ($tokens as $pos => $token) {
            if ($token === '{') {
                $innerTokens = array_slice($tokens, $pos + 1, count($tokens) - $pos - 2);
                $headerTokens = array_slice($tokens, 0, $pos - 1);
                break;
            }
        }

        $functionComponent = new FunctionComponent();

        try {
            $functionComponent->name    = $this->extractName($headerTokens);
        } catch (\Exception $e) {
            //var_dump($e->getTrace());
        }

        $functionComponent->params  = $this->extractParams($headerTokens);
        $functionComponent->callMethods = $this->extractCalledFunctions($innerTokens);
        $functionComponent->instantiated = $this->extractInstantiatedClasses($innerTokens);

        return $functionComponent;
    }

    private function extractName(array $tokens)
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        throw new \Exception("Function name is missing");
    }

    private function extractParams(array $tokens)
    {
        $params = [];

        foreach ($this->paramAnalyzer->extract($tokens) as $paramComponent) {
            $params[] = $paramComponent;
        }

        return $params;
    }

    private function extractCalledFunctions(array $tokens)
    {
        $called = [];

        $tokens = array_filter($tokens, function ($token) {
            return !is_array($token) || $token[0] !== T_WHITESPACE;
        });

        $tokens = array_values($tokens);

        foreach ($tokens as $pos => $token) {
            if (is_array($token)
                && $token[0] === T_STRING
                && isset($tokens[$pos + 1])
                && $tokens[$pos + 1] === '('
                && (!isset($tokens[$pos - 1])
                    || !in_array($tokens[$pos - 1][0], [T_OBJECT_OPERATOR, T_NEW], true)
            )) {
                $called[] = $token[1];
            }
        }

        return $called;
    }

    private function extractInstantiatedClasses(array $tokens)
    {
        $instantiated = [];

        $tokens = array_filter($tokens, function ($token) {
            return !is_array($token) || $token[0] !== T_WHITESPACE;
        });

        $tokens = array_values($tokens);

        foreach ($tokens as $pos => $token) {
            if (is_array($token)
                && $token[0] === T_STRING
                && isset($tokens[$pos - 1])
                && is_array($tokens[$pos - 1])
                && $tokens[$pos - 1][0] === T_NEW
            ) {
                $instantiated[] = $token[1];
            }
        }

        return $instantiated;
    }
}

class ParamAnalyzer implements ContentAnalyzer
{
    public function extract(array $tokens) : \Generator
    {
        $inParam = false;
        $isAssignment = false;
        $nestedListLevel = 0;

        $currParamTokens = [];

        foreach ($tokens as $token) {
            if (!$inParam && $token === '(') {
                $inParam = true;
                continue;
            } elseif ($inParam && $nestedListLevel === 0 && $token === ')') {
                if (!empty($currParamTokens)) {
                    yield $this->createParam($currParamTokens);
                }

                break;
            }

            if (!$inParam) {
                continue;
            }

            if ($token === ',' && $nestedListLevel === 0) {
                yield $this->createParam($currParamTokens);
                $currParamTokens = [];
                $isAssignment = false;
                $nestedListLevel = 0;
            } else {
                if ($token === '=') {
                    $isAssignment = true;
                } elseif ($isAssignment && in_array($token, ['[', '('])) {
                    $nestedListLevel++;
                } elseif ($isAssignment && in_array($token, [']', ')'])) {
                    $nestedListLevel--;
                }

                $currParamTokens[] = $token;
            }
        }
    }

    private function createParam(array $tokens)
    {
        $paramComponent = new ParamComponent();

        $isAssignment = false;
        $assignmentTokens = [];

        foreach ($tokens as $token) {
            if ($token === '=') {
                $isAssignment = true;
                continue;
            }

            if ($isAssignment) {
                if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                    $assignmentTokens[] = $token;
                }

                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            switch ($token[0]) {
                case T_NS_SEPARATOR:
                case T_STRING:
                    $paramComponent->type .= $token[1];
                    break;
                case T_ARRAY:
                    $paramComponent->type = 'array';
                    break;
                case T_VARIABLE:
                    $paramComponent->name = $token[1];
                    break;
                case T_ELLIPSIS:
                    $paramComponent->isVariadic = true;
                    break;
            }
        }

        static::assignDefaultValue($paramComponent, $assignmentTokens);

        return $paramComponent;
    }

    public static function assignDefaultValue(VariableComponent $varComponent, array $assignmentTokens)
    {
        switch (count($assignmentTokens)) {
            case 0:
                break;
            case 1 and is_array($assignmentTokens[0]):
                $varComponent->hasDefaultValue = true;
                switch ($assignmentTokens[0][1]) {
                    case 'array':
                        $varComponent->defaultValue = [];
                        break;
                    case 'null':
                        $varComponent->defaultValue = null;
                        break;
                    default:
                        $varComponent->defaultValue = $assignmentTokens[0][1];
                        break;
                }
                break;
            default:
                $defaultValue = array_map(function ($token) {
                    if ($token === '(') {
                        return '[';
                    } elseif ($token === ')') {
                        return ']';
                    } elseif (!is_array($token)) {
                        return $token;
                    }

                    if ($token[0] !== T_ARRAY) {
                        return $token[1];
                    }
                }, $assignmentTokens);

                //@todo convert it into an array
                $defaultValue = array_reduce($defaultValue, function ($current, $value) {
                    return $current . $value;
                });

                $varComponent->hasDefaultValue = true;
                $varComponent->defaultValue = $defaultValue;
                break;
        }
    }
}

$janus = new Janus(
    new FileAnalyzer(
        new ClassAnalyzer(new FunctionAnalyzer(new ParamAnalyzer())),
        new FunctionAnalyzer(new ParamAnalyzer())
    )
);


$begin = microtime(true);
$nbFiles = 0;
$nbComponents = 0;

foreach (analyzeDir($_SERVER['argv'][1]) as $subpath) {
    $fileContent = [
        'filepath' => $subpath,
        'components' => []
    ];

    foreach ($janus->analyze($subpath) as $phpComponent) {
        $fileContent['components'][] = $phpComponent->toArray();
        $nbComponents++;
    }

    echo json_encode($fileContent) . "\n";
    $nbFiles++;
}

echo "\n\n\n";
echo "Time : " . round(microtime(true) - $begin, 3) . "s\n";
echo "Nb files : " . $nbFiles . "\n";
echo "Nb components : " . $nbComponents . "\n";
echo "Peak memory : " . round(memory_get_peak_usage(true) / 1024 / 1024, 3) . "MB\n";

function analyzeDir(string $path)
{
    foreach (scandir($path) as $file) {
        if (in_array($file, ['.', '..'])) {
            continue;
        }

        $subpath = $path . '/' . $file;

        if (is_dir($subpath)) {
            yield from analyzeDir($subpath);
        } elseif (strtolower(substr($subpath, strlen($subpath) - 3)) === 'php') {
            yield $subpath;
        }
    }
}
