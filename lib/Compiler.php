<?php

declare(strict_types=1);

namespace FFIMe;
use PHPCParser\CParser;
use PHPCParser\Node\Decl;
use PHPCParser\Node\TranslationUnitDecl;
use PHPCParser\Node\Type;
use PHPCParser\Printer;
use PHPCParser\Node\Stmt\ValueStmt\Expr;

class Compiler {

    private array $defines;
    private array $resolver; 

    public function compile(string $soFile, array $decls, array $defines, string $className): string {
        $this->defines = $defines;
        $this->resolver = $this->buildResolver($decls);
        $parts = explode('\\', $className);
        $class = [];
        if (isset($parts[1])) {
            $className = array_pop($parts);
            $namespace = implode('\\', $parts);
            $class[] = "namespace " . $namespace . ";";
            $class[] = "use FFI;";
        }
        $class[] = "interface i{$className} {}";
        $class[] = "class $className {";
        $class[] = "    const SOFILE = " . var_export($soFile, true) . ';';
        $class[] = "    const HEADER_DEF = " . var_export($this->compileDeclsToCode($decls), true) . ';';
        $class[] = "    private FFI \$ffi;";
        foreach ($defines as $define => $value) {
            // remove type qualifiers
            $value = str_replace(['u', 'l', 'U', 'L'], '', $value);
            if (strpos($value, '.') !== false) {
                $value = str_replace(['d', 'f', 'D', 'F'], '', $value);
            }
            $class[] = "    const {$define} = {$value};";
        }
        $class[] = $this->compileConstructor();
        $class[] = $this->compileMethods($className);
        $class[] = "    public function __get(string \$name) {";
        $class[] = "        switch(\$name) {";
        foreach ($this->compileCases($decls) as $case) {
            $class[] = "            $case";
        }
        $class[] = "            default: return \$this->ffi->\$name;";
        $class[] = "        }";
        $class[] = "    }";
        foreach ($decls as $decl) {
            $class = array_merge($class, $this->compileDecl($decl));
        }
        $class[] = "}\n";
        $this->compileDeclClassImpl('void_ptr', 'void*', $className);
        $this->compileDeclClassImpl('void_ptr_ptr', 'void**', $className);
        $this->compileDeclClassImpl('void_ptr_ptr_ptr', 'void***', $className);
        foreach ($decls as $decl) {
            $class = array_merge($class, $this->compileDeclClass($decl, $className));
        }
        return implode("\n", $class);
    }

    public function compileCases(array $decls): array {
        $results = [];
        foreach ($decls as $decl) {
            if ($decl instanceof Decl\NamedDecl\ValueDecl\DeclaratorDecl\VarDecl) {
                $return = $this->compileType($decl->type);
                if (in_array($return, self::NATIVE_TYPES)) {
                    $results[] = "case " . var_export($decl->name, true) . ": return \$this->ffi->{$decl->name};";
                } else {
                    $results[] = "case " . var_export($decl->name, true) . ": \$tmp = \$this->ffi->{$decl->name}; return \$tmp === null ? null : new $return(\$tmp);";
                }
            }
        }
        return $results;
    }

    protected function compileConstructor(): string {
        return '    public function __construct(string $pathToSoFile = self::SOFILE) {
        $this->ffi = FFI::cdef(self::HEADER_DEF, $pathToSoFile);
    }';
    }

    protected function compileMethods(string $className): string {
        return '    
    public function cast(i'. $className . ' $from, string $to): i' . $className . ' {
        if (!is_a($to, i' . $className . '::class)) {
            throw new \LogicException("Cannot cast to a non-wrapper type");
        }
        return new $to($this->ffi->cast($to::getType(), $from->getData()));
    }

    public function makeArray(string $class, array $elements) {
        $type = $class::getType();
        if (substr($type, -1) !== "*") {
            throw new \LogicException("Attempting to make a non-pointer element into an array");
        }
        $cdata = $this->ffi->new(substr($type, 0, -1) . "[" . count($elements) . "]");
        foreach ($elements as $key => $raw) {
            $cdata[$key] = $raw === null ? null : $raw->getData();
        }
        return new $class($cdata);
    }

    public function sizeof($classOrObject): int {
        if (is_object($classOrObject) && $classOrObject instanceof i' . $className . ') {
            return $this->ffi->sizeof($classOrObject->getData());
        } elseif (is_a($classOrObject, i' . $className . '::class)) {
            return $this->ffi->sizeof($this->ffi->type($classOrObject::getType()));
        } else {
            throw new \LogicException("Unknown class/object passed to sizeof()");
        }
    }

    public function getFFI(): FFI {
        return $this->ffi;
    }

    ';
    }
    
    public function compileDeclsToCode(array $decls): string {
        // TODO
        $printer = new Printer\C;
        return $printer->printNodes($decls, 0);
    }

    public function compileDecl(Decl $declaration): array {
        $return = [];
        if ($declaration instanceof Decl\NamedDecl\ValueDecl\DeclaratorDecl\FunctionDecl) {
            $returnType = $this->compileType($declaration->type->return);
            $params = $this->compileParameters($declaration->type->params);
            $nullableReturnType = $returnType === 'void' ? 'void' : '?' . $returnType;
            $paramSignature = [];
            foreach ($params as $idx => $param) {
                $paramSignature[] = '?' . $param . ' $p' . $idx;
            }
            $return[] = "    public function {$declaration->name}(" . implode(', ', $paramSignature) . "): " . $nullableReturnType . " {";
            
            $callParams = [];
            foreach ($params as $idx => $param) {
                if (in_array($param, self::NATIVE_TYPES)) {
                    $callParams[] = '$p' . $idx;
                } else {
                    $callParams[] = '$p' . $idx . ' === null ? null : $p' . $idx . '->getData()';
                }
            }
            if ($returnType !== 'void') {
                $return[] = '        $result = $this->ffi->' . $declaration->name . '(' . implode(', ', $callParams) . ');';
                if (in_array($returnType, self::NATIVE_TYPES)) {
                    $return[] = '        return $result;';
                } else {
                    $return[] = '        return $result === null ? null : new ' . $returnType . '($result);';
                }
            } else {
                $return[] = '        $this->ffi->' . $declaration->name . '(' . implode(', ', $callParams) . ');';
            }
            $return[] = "    }";
        } elseif ($declaration instanceof Decl\NamedDecl\TypeDecl\TagDecl\EnumDecl) {
            if ($declaration->name !== null) {
                $return[] = "    // enum {$declaration->name}";
            }
enum_decl:
            if ($declaration->fields !== null) {
                $id = 0;
                foreach ($declaration->fields as $field) {
                    if (isset($this->defines[$field->name])) {
                        $id++;
                        continue;
                    }
                    $return[] = "    const {$field->name} = " . ($field->value === null ? $id++ : $this->compileExpr($field->value)) . ";";
                }
            }
        } elseif ($declaration instanceof Decl\NamedDecl\TypeDecl\TypedefNameDecl && $declaration->type instanceof Type\TagType\EnumType) {
            $return[] = " // typedefenum {$declaration->name}";
            $declaration = $declaration->type->decl;
            goto enum_decl;
        }
        return $return;
    }

    public function compileExpr(Expr $expr): string {
        if ($expr instanceof Expr\IntegerLiteral) {
            // parse out type qualifiers
            $value = str_replace(['u', 'U', 'l', 'L'], '', $expr->value);
            return (string) intval($expr->value);
        }
        if ($expr instanceof Expr\UnaryOperator) {
            switch ($expr->kind) {
                case Expr\UnaryOperator::KIND_PLUS:
                    return '(+' . $this->compileExpr($expr->expr) . ')';
                case Expr\UnaryOperator::KIND_MINUS:
                    return '(-' . $this->compileExpr($expr->expr) . ')';
                case Expr\UnaryOperator::KIND_BITWISE_NOT:
                    return '(~' . $this->compileExpr($expr->expr) . ')';
                case Expr\UnaryOperator::KIND_LOGICAL_NOT:
                    return '(!' . $this->compileExpr($expr->expr) . ')';
                default:
                    throw new \LogicException("Unsupported unary operator for library: " . $expr->kind);
            }
        }
        var_dump($expr);
    }

    public function compileParameters(array $params): array {
        if (empty($params)) {
            return [];
        } elseif ($params[0] instanceof Type\BuiltinType && $params[0]->name === 'void') {
            return [];
        }
        $return = [];
        foreach ($params as $idx => $param) {
            $return[] =  $this->compileType($param);
        }
        return $return;
    }

    private const INT_TYPES = [
        'char',
        'int',
        'unsigned',
        'unsigned int',
        'long',
        'long long',
        'long int',
        'long long int',
        'int64_t',
        'unsigned long long',
        'unsigned long long int',

    ];

    private const FLOAT_TYPES = [
        'float',
        'double',
        'long double',
    ];

    private const NATIVE_TYPES = [
        'int',
        'float',
        'bool',
        'string',
        'array',
    ];

    public function compileType(Type $type): string {
        if ($type instanceof Type\TypedefType) {
            if (in_array($type->name, self::INT_TYPES)) {
                return 'int';
            }
            if (in_array($type->name, self::FLOAT_TYPES)) {
                return 'float';
            }
            return $type->name;
        } elseif ($type instanceof Type\BuiltinType && $type->name === 'void') {
            return 'void';
        } elseif ($type instanceof Type\BuiltinType && in_array($type->name, self::INT_TYPES)) {
            return 'int';
        } elseif ($type instanceof Type\BuiltinType && in_array($type->name, self::FLOAT_TYPES)) {
            return 'float';
        } elseif ($type instanceof Type\TagType\EnumType) {
            return 'int';
        } elseif ($type instanceof Type\PointerType) {
            // special case
            if ($type->parent instanceof Type\BuiltinType && $type->parent->name === 'char') {
                // it's a string
                return 'string';
            }
            return $this->compileType($type->parent) . '_ptr';
        } elseif ($type instanceof Type\AttributedType) {
            if ($type->kind === Type\AttributedType::KIND_CONST) {
                // we can omit const from our compilation
                return $this->compileType($type->parent);
            } elseif ($type->kind === Type\AttributedType::KIND_EXTERN) {
                return $this->compileType($type->parent);
            }
        } elseif ($type instanceof Type\TagType\RecordType) {
            if ($type->decl->name !== null) {
                return $type->decl->name;
            }
        } elseif ($type instanceof Type\ArrayType\ConstantArrayType) {
            return 'array';
        } elseif ($type instanceof Type\ArrayType\IncompleteArrayType) {
            return 'array';
        }
        var_dump($type);
        throw new \LogicException('Not implemented how to handle type yet: ' . get_class($type));
    }

    public function compileDeclClass(Decl $decl, string $className): array {
        $return = [];
        if ($decl instanceof Decl\NamedDecl\TypeDecl\TypedefNameDecl\TypedefDecl) {
            $return = array_merge($return, $this->compileDeclClassImpl($decl->name, $decl->name, $className));
            for ($i = 1; $i <= 4; $i++) {
                $return = array_merge($return, $this->compileDeclClassImpl($decl->name . str_repeat('_ptr', $i), $decl->name . str_repeat('*', $i), $className));
            }
        }
        return $return;
    }
    

    protected function compileDeclClassImpl(string $name, string $ptrName, string $className): array {
        $return = [];
        $return[] = "class {$name} implements i{$className} {";
        $return[] = '    private FFI\CData $data;';
        if (!isset($this->resolver[$name])) {
            $return[] = '    public function __construct(FFI\CData $data) { $this->data = $data; }';
        } else {
            $return[] = '    public function __construct($data) { $tmp = FFI::new(' . var_export($this->resolver[$name], true) . '); $tmp = $data; $this->data = $tmp; }';
        }
        $return[] = '    public function getData(): FFI\CData { return $this->data; }';
        $return[] = '    public function equals(' . $name . ' $other): bool { return $this->data == $other->data; }';
        $return[] = '    public function addr(): ' . $name . '_ptr { return new '. $name . '_ptr(FFI::addr($this->data)); }';
        if (substr($name, -4) === '_ptr') {
            $prior = substr($name, 0, -4);
            $return[] = '    public function deref(int $n = 0): ' . $prior . ' { return new ' . $prior . '($this->data[$n]); }';
        }
        $return[] = '    public static function getType(): string { return ' . var_export($ptrName, true) . '; }';
        $return[] = '}';
        return $return;
    }

    protected function buildResolver(array $decls): array {
        $toLookup = [];
        $result = [];
        foreach ($decls as $decl) {
            if ($decl instanceof Decl\NamedDecl\TypeDecl\TypedefNameDecl\TypedefDecl) {
                if ($decl->type instanceof Type\TypedefType) {
                    $toLookup[] = [$decl->name, $decl->type->name];
                } elseif ($decl->type instanceof Type\BuiltinType) {
                    $result[$decl->name] = $decl->type->name;
                }
            }
        }
        $runs = 1000;
        while ($runs-- > 0 && !empty($toLookup)) {
            do {
                list ($name, $ref) = array_shift($toLookup);
                if (isset($result[$ref])) {
                    $result[$name] = $result[$ref];
                } else {
                    // re-queue, recursive lookup?
                    $toLookup[] = [$name, $ref];
                }
            } while (!empty($toLookup));
        }
        return $result;
    }

}