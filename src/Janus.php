<?php

namespace MKCG\Janus;

interface ContentAnalyzer
{
    public function extract(array $tokens) : \Generator;
}

abstract class PhpComponent
{
    public $name;

    public function toArray() : array
    {
        $data = [];

        foreach (array_keys(get_object_vars($this)) as $name) {
            if ($this->$name instanceof PhpComponent) {
                $data[$name] = $this->$name->toArray();
            } elseif (is_array($this->$name)) {
                $data[$name] = array_map(function($value) {
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

class InterfaceComponent extends PhpComponent
{
    public $namespace;

    public $methods = [];
}

class ClassComponent extends InterfaceComponent
{
    public $uses = [];

    public $interfaces = [];

    public $extends;

    public $traits = [];

    public $traitRenamedMethods = [];

    public $isAbstract = false;
}

class FunctionComponent extends PhpComponent
{
    public $params = [];

    public $returnType;

    public $namespace;

    public $class;

    public $visibility;

    public $isStatic = false;

    public $isAnonym = false;

    public $isAbstract = false;

    public $isFinal = false;
}

class ParamComponent extends PhpComponent
{
    public $type;

    public $hasDefaultValue;

    public $defaultValue;

    public $isVariadic = false;
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
        foreach ($this->classAnalyzer->extract($tokens) as $component) {
            yield $component;
        }
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

        foreach ($tokens as $pos => $token) {
            if (!isset($currentClassTokens[0]) && is_array($token) && in_array($token[0], [T_ABSTRACT, T_FINAL, T_CLASS], true)) {
                $followingTokens = array_slice($tokens, $pos + 1, 4);
                $tokenTypes = array_column($followingTokens, 0);

                $isClass = $token[0] === T_CLASS || in_array(T_CLASS, $tokenTypes, true);

                if (!$isClass) {
                    continue;
                }

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
                    $classComponent = $this->createClass($currentClassTokens);
                    $classComponent->isAbstract = $isAbstract;
                    $classComponent->isFinal    = $isFinal;

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
        $component->methods     = $this->extractMethods($tokens);

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
        foreach ($tokens as $pos => $token) {
            if ($token === '{') {
                $tokens = array_slice($tokens, $pos + 1, count($tokens) - $pos - 2);
                break;
            }
        }

        $methods = [];

        foreach ($this->functionAnalyzer->extract($tokens) as $component) {
            $methods[] = $component;
        }

        return $methods;
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

                $isAbstract = $token[0] === T_ABSTRACT || in_array(T_ABSTRACT,  $followingTypes, true);
                $isStatic   = $token[0] === T_STATIC   || in_array(T_STATIC,    $followingTypes, true);
                $isFinal    = $token[0] === T_FINAL    || in_array(T_FINAL,     $followingTypes, true);

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

                    yield $functionComponent;

                    $isMethod       = false;
                    $isAbstract     = false;
                    $isStatic       = false;
                    $isFinal        = false;
                    $isAnonym       = false;
                    $visibility     = 'public';
                    $nestedLevel    = 0;

                    $currFunctionTokens = [];
                }
            }
        }
    }

    private function createFunction(array $tokens)
    {
        $functionComponent = new FunctionComponent();

        try {
            $functionComponent->name    = $this->extractName($tokens);
        } catch (\Exception $e) {

        }

        $functionComponent->params  = $this->extractParams($tokens);

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
            } elseif ($inParam && $nestedListLevel === 0 && $token === ')') {
                yield $this->createParam($currParamTokens);
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

        switch (count($assignmentTokens)) {
            case 0:
                break;
            case 1 and is_array($assignmentTokens[0]):
                $paramComponent->hasDefaultValue = true;
                switch ($assignmentTokens[0][1]) {
                    case 'array':
                        $paramComponent->defaultValue = [];
                        break;
                    case 'null':
                        $paramComponent->defaultValue = null;
                        break;
                    default:
                        $paramComponent->defaultValue = $assignmentTokens[0][1];
                        break;
                }
                break;
            default:
                $defaultValue = array_map(function($token) {
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
                $defaultValue = array_reduce($defaultValue, function($current, $value) {
                    return $current . $value;
                });

                $paramComponent->defaultValue = $defaultValue;
                break;
        }

        return $paramComponent;
    }
}

$janus = new Janus(
    new FileAnalyzer(
        new ClassAnalyzer(new FunctionAnalyzer(new ParamAnalyzer())),
        new FunctionAnalyzer(new ParamAnalyzer())
));


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
