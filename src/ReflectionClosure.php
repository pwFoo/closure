<?php
/* ===========================================================================
 * Copyright (c) 2018-2020 Zindex Software
 *
 * Licensed under the MIT License
 * =========================================================================== */

namespace Opis\Closure;

use Closure;
use ReflectionFunction;

class ReflectionClosure extends ReflectionFunction
{
    protected ?string $code = null;
    protected ?array $tokens = null;
    protected ?string $hashedName = null;
    protected ?array $useVariables = null;
    protected ?bool $isStaticClosure = null;
    protected ?bool $isScopeRequired = null;
    protected ?bool $isBindingRequired = null;
    protected ?bool $isShortClosure = null;

    protected static array $files = [];
    protected static array $classes = [];
    protected static array $functions = [];
    protected static array $constants = [];
    protected static array $structures = [];


    /**
     * ReflectionClosure constructor.
     * @param Closure $closure
     * @param string|null $code
     * @throws \ReflectionException
     */
    public function __construct(Closure $closure, ?string $code = null)
    {
        $this->code = $code;
        parent::__construct($closure);
    }

    /**
     * @return bool
     */
    public function isFromCallable(): bool
    {
        return $this->getShortName() !== '{closure}';
    }

    /**
     * @return bool
     */
    public function isClassMethod(): bool
    {
        if (!$this->isFromCallable()) {
            return false;
        }

        if ($this->getNamespaceName()) {
            // We have a namespace, so it is a function
            return false;
        }

        return $this->getClosureScopeClass() !== null;
    }

    /**
     * @return bool
     */
    public function isStatic(): bool
    {
        if ($this->isStaticClosure === null) {
            // TODO: only check if has a $this bound
            $this->isStaticClosure = strtolower(substr($this->getCode(), 0, 6)) === 'static';
        }

        return $this->isStaticClosure;
    }

    /**
     * @return bool
     */
    public function isShortClosure(): bool
    {
        if ($this->isInternal()) {
            return false;
        }

        if ($this->isShortClosure === null) {
            $code = $this->getCode();
            if ($this->isStatic()) {
                $code = substr($code, 6);
            }
            $this->isShortClosure = strtolower(substr(ltrim($code), 0, 2)) === 'fn';
        }

        return $this->isShortClosure;
    }

    /**
     * @return string|null
     */
    public function getCode(): ?string
    {
        if ($this->isInternal()) {
            return null;
        }

        if($this->code !== null){
            return $this->code;
        }

        $fileName = $this->getFileName();
        $line = $this->getStartLine() - 1;

        $className = null;

        if ((null !== $className = $this->getClosureScopeClass()) && !$className->isInternal()) {
            $className = '\\' . trim($className->getName(), '\\');
        }

        $php_types = ['string', 'int', 'float', 'bool', 'void', 'array', 'object', 'callable', 'iterable'];

        $ns = $this->getNamespaceName();
        $nsf = $ns == '' ? '' : ($ns[0] == '\\' ? $ns : '\\' . $ns);

        $_file = var_export($fileName, true);
        $_dir = var_export(dirname($fileName), true);
        $_namespace = var_export($ns, true);
        $_class = var_export(trim($className, '\\'), true);
        $_function = $ns . ($ns == '' ? '' : '\\') . '{closure}';
        $_method = ($className == '' ? '' : trim($className, '\\') . '::') . $_function;
        $_function = var_export($_function, true);
        $_method = var_export($_method, true);
        $_trait = null;

        $tokens = $this->getTokens();

        $state = $lastState = 'start';
        $inside_anonymous = false;
        $isShortClosure = false;
        $anonymous_mark = 0;
        $open = 0;
        $code = '';
        $id_start = $id_start_ci = $id_name = $context = '';
        $classes = $functions = $constants = null;
        $use = [];
        $lineAdd = 0;
        $isUsingScope = false;
        $isUsingThisObject = false;

        for($i = 0, $l = count($tokens); $i < $l; $i++) {
            $token = $tokens[$i];
            switch ($state) {
                case 'start':
                    if ($token[0] === T_FUNCTION || $token[0] === T_STATIC) {
                        $code .= $token[1];
                        $state = $token[0] === T_FUNCTION ? 'function' : 'static';
                    } elseif ($token[0] === T_FN) {
                        $isShortClosure = true;
                        $code .= $token[1];
                        $state = 'closure_args';
                    }
                    break;
                case 'static':
                    if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_FUNCTION) {
                        $code .= $token[1];
                        if ($token[0] === T_FUNCTION) {
                            $state = 'function';
                        }
                    } elseif ($token[0] === T_FN) {
                        $isShortClosure = true;
                        $code .= $token[1];
                        $state = 'closure_args';
                    } else {
                        $code = '';
                        $state = 'start';
                    }
                    break;
                case 'function':
                    switch ($token[0]){
                        case T_STRING:
                            $code = '';
                            $state = 'named_function';
                            break;
                        case '(':
                            $code .= '(';
                            $state = 'closure_args';
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                    }
                    break;
                case 'named_function':
                    if($token[0] === T_FUNCTION || $token[0] === T_STATIC){
                        $code = $token[1];
                        $state = $token[0] === T_FUNCTION ? 'function' : 'static';
                    } elseif ($token[0] === T_FN) {
                        $isShortClosure = true;
                        $code .= $token[1];
                        $state = 'closure_args';
                    }
                    break;
                case 'closure_args':
                    switch ($token[0]){
                        case T_NS_SEPARATOR:
                        case T_STRING:
                            $id_start = $token[1];
                            $id_start_ci = strtolower($id_start);
                            $id_name = '';
                            $context = 'args';
                            $state = 'id_name';
                            $lastState = 'closure_args';
                            break;
                        case T_USE:
                            $code .= $token[1];
                            $state = 'use';
                            break;
                        case T_DOUBLE_ARROW:
                            $code .= $token[1];
                            if ($isShortClosure) {
                                $state = 'closure';
                            }
                            break;
                        case '=':
                            $code .= $token;
                            $lastState = 'closure_args';
                            $state = 'ignore_next';
                            break;
                        case ':':
                            $code .= ':';
                            $state = 'return';
                            break;
                        case '{':
                            $code .= '{';
                            $state = 'closure';
                            $open++;
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                    }
                    break;
                case 'use':
                    switch ($token[0]){
                        case T_VARIABLE:
                            $use[] = substr($token[1], 1);
                            $code .= $token[1];
                            break;
                        case '{':
                            $code .= '{';
                            $state = 'closure';
                            $open++;
                            break;
                        case ':':
                            $code .= ':';
                            $state = 'return';
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                            break;
                    }
                    break;
                case 'return':
                    switch ($token[0]){
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            $code .= $token[1];
                            break;
                        case T_NS_SEPARATOR:
                        case T_STRING:
                            $id_start = $token[1];
                            $id_start_ci = strtolower($id_start);
                            $id_name = '';
                            $context = 'return_type';
                            $state = 'id_name';
                            $lastState = 'return';
                            break 2;
                        case T_DOUBLE_ARROW:
                            $code .= $token[1];
                            if ($isShortClosure) {
                                $state = 'closure';
                            }
                            break;
                        case '{':
                            $code .= '{';
                            $state = 'closure';
                            $open++;
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                            break;
                    }
                    break;
                case 'closure':
                    switch ($token[0]){
                        case T_CURLY_OPEN:
                        case T_DOLLAR_OPEN_CURLY_BRACES:
                        case T_STRING_VARNAME:
                        case '{':
                            $code .= '{';
                            $open++;
                            break;
                        case '}':
                            $code .= '}';
                            if(--$open === 0 && !$isShortClosure){
                                break 3;
                            } elseif ($inside_anonymous) {
                                $inside_anonymous = !($open === $anonymous_mark);
                            }
                            break;
                        case '(':
                        case '[':
                            $code .= $token[0];
                            if ($isShortClosure) {
                                $open++;
                            }
                            break;
                        case ')':
                        case ']':
                            if ($isShortClosure) {
                                if ($open === 0) {
                                    break 3;
                                }
                                --$open;
                            }
                            $code .= $token[0];
                            break;
                        case ',':
                        case ';':
                            if ($isShortClosure && $open === 0) {
                                break 3;
                            }
                            $code .= $token[0];
                            break;
                        case T_LINE:
                            $code .= $token[2] - $line + $lineAdd;
                            break;
                        case T_FILE:
                            $code .= $_file;
                            break;
                        case T_DIR:
                            $code .= $_dir;
                            break;
                        case T_NS_C:
                            $code .= $_namespace;
                            break;
                        case T_CLASS_C:
                            $code .= $_class;
                            break;
                        case T_FUNC_C:
                            $code .= $_function;
                            break;
                        case T_METHOD_C:
                            $code .= $_method;
                            break;
                        case T_COMMENT:
                            if (substr($token[1], 0, 8) === '#trackme') {
                                $timestamp = time();
                                $code .= '/**' . PHP_EOL;
                                $code .= '* Date      : ' . date(DATE_W3C, $timestamp) . PHP_EOL;
                                $code .= '* Timestamp : ' . $timestamp . PHP_EOL;
                                $code .= '* Line      : ' . ($line + 1) . PHP_EOL;
                                $code .= '* File      : ' . $_file . PHP_EOL . '*/' . PHP_EOL;
                                $lineAdd += 5;
                            } else {
                                $code .= $token[1];
                            }
                            break;
                        case T_VARIABLE:
                            if($token[1] == '$this' && !$inside_anonymous){
                                $isUsingThisObject = true;
                            }
                            $code .= $token[1];
                            break;
                        case T_STATIC:
                            $isUsingScope = true;
                            $code .= $token[1];
                            break;
                        case T_NS_SEPARATOR:
                        case T_STRING:
                            $id_start = $token[1];
                            $id_start_ci = strtolower($id_start);
                            $id_name = '';
                            $context = 'root';
                            $state = 'id_name';
                            $lastState = 'closure';
                            break 2;
                        case T_NEW:
                            $code .= $token[1];
                            $context = 'new';
                            $state = 'id_start';
                            $lastState = 'closure';
                            break 2;
                        case T_USE:
                            $code .= $token[1];
                            $context = 'use';
                            $state = 'id_start';
                            $lastState = 'closure';
                            break;
                        case T_INSTANCEOF:
                            $code .= $token[1];
                            $context = 'instanceof';
                            $state = 'id_start';
                            $lastState = 'closure';
                            break;
                        case T_OBJECT_OPERATOR:
                        case T_DOUBLE_COLON:
                            $code .= $token[1];
                            $lastState = 'closure';
                            $state = 'ignore_next';
                            break;
                        case T_FUNCTION:
                            $code .= $token[1];
                            $state = 'closure_args';
                            break;
                        case T_TRAIT_C:
                            if ($_trait === null) {
                                $startLine = $this->getStartLine();
                                $endLine = $this->getEndLine();
                                $structures = $this->getStructures();

                                $_trait = '';

                                foreach ($structures as &$struct) {
                                    if ($struct['type'] === 'trait' &&
                                        $struct['start'] <= $startLine &&
                                        $struct['end'] >= $endLine
                                    ) {
                                        $_trait = ($ns == '' ? '' : $ns . '\\') . $struct['name'];
                                        break;
                                    }
                                }

                                $_trait = var_export($_trait, true);
                            }

                            $code .= $_trait;
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                    }
                    break;
                case 'ignore_next':
                    switch ($token[0]){
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            $code .= $token[1];
                            break;
                        case T_CLASS:
                        case T_NEW:
                        case T_STATIC:
                        case T_VARIABLE:
                        case T_STRING:
                        case T_CLASS_C:
                        case T_FILE:
                        case T_DIR:
                        case T_METHOD_C:
                        case T_FUNC_C:
                        case T_FUNCTION:
                        case T_INSTANCEOF:
                        case T_LINE:
                        case T_NS_C:
                        case T_TRAIT_C:
                        case T_USE:
                            $code .= $token[1];
                            $state = $lastState;
                            break;
                        default:
                            $state = $lastState;
                            $i--;
                    }
                    break;
                case 'id_start':
                    switch ($token[0]){
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            $code .= $token[1];
                            break;
                        case T_NS_SEPARATOR:
                        case T_STRING:
                        case T_STATIC:
                            $id_start = $token[1];
                            $id_start_ci = strtolower($id_start);
                            $id_name = '';
                            $state = 'id_name';
                            break 2;
                        case T_VARIABLE:
                            $code .= $token[1];
                            $state = $lastState;
                            break;
                        case T_CLASS:
                            $code .= $token[1];
                            $state = 'anonymous';
                            break;
                        default:
                            $i--;//reprocess last
                            $state = 'id_name';
                    }
                    break;
                case 'id_name':
                    switch ($token[0]){
                        case T_NS_SEPARATOR:
                        case T_STRING:
                            $id_name .= $token[1];
                            break;
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            $id_name .= $token[1];
                            break;
                        case '(':
                            if ($isShortClosure) {
                                $open++;
                            }
                            if($context === 'new' || false !== strpos($id_name, '\\')){
                                if($id_start !== '\\'){
                                    if ($classes === null) {
                                        $classes = $this->getClasses();
                                    }
                                    if (isset($classes[$id_start_ci])) {
                                        $id_start = $classes[$id_start_ci];
                                    }
                                    if($id_start[0] !== '\\'){
                                        $id_start = $nsf . '\\' . $id_start;
                                    }
                                }
                            } else {
                                if($id_start !== '\\'){
                                    if($functions === null){
                                        $functions = $this->getFunctions();
                                    }
                                    if(isset($functions[$id_start_ci])){
                                        $id_start = $functions[$id_start_ci];
                                    }
                                }
                            }
                            $code .= $id_start . $id_name . '(';
                            $state = $lastState;
                            break;
                        case T_VARIABLE:
                        case T_DOUBLE_COLON:
                            if($id_start !== '\\') {
                                if($id_start_ci === 'self' || $id_start_ci === 'static' || $id_start_ci === 'parent'){
                                    $isUsingScope = true;
                                } elseif (!in_array($id_start_ci, $php_types)){
                                    if ($classes === null) {
                                        $classes = $this->getClasses();
                                    }
                                    if (isset($classes[$id_start_ci])) {
                                        $id_start = $classes[$id_start_ci];
                                    }
                                    if($id_start[0] !== '\\'){
                                        $id_start = $nsf . '\\' . $id_start;
                                    }
                                }
                            }
                            $code .= $id_start . $id_name . $token[1];
                            $state = $token[0] === T_DOUBLE_COLON ? 'ignore_next' : $lastState;
                            break;
                        default:
                            if($id_start !== '\\'){
                                if($context === 'use' ||
                                    $context === 'instanceof' ||
                                    $context === 'args' ||
                                    $context === 'return_type' ||
                                    $context === 'extends'
                                ){
                                    if($id_start_ci === 'self' || $id_start_ci === 'static' || $id_start_ci === 'parent'){
                                        $isUsingScope = true;
                                    } elseif (!in_array($id_start_ci, $php_types)){
                                        if($classes === null){
                                            $classes = $this->getClasses();
                                        }
                                        if(isset($classes[$id_start_ci])){
                                            $id_start = $classes[$id_start_ci];
                                        }
                                        if($id_start[0] !== '\\'){
                                            $id_start = $nsf . '\\' . $id_start;
                                        }
                                    }
                                } else {
                                    if($constants === null){
                                        $constants = $this->getConstants();
                                    }
                                    if(isset($constants[$id_start])){
                                        $id_start = $constants[$id_start];
                                    }
                                }
                            }
                            $code .= $id_start . $id_name;
                            $state = $lastState;
                            $i--;//reprocess last token
                    }
                    break;
                case 'anonymous':
                    switch ($token[0]) {
                        case T_NS_SEPARATOR:
                        case T_STRING:
                            $id_start = $token[1];
                            $id_start_ci = strtolower($id_start);
                            $id_name = '';
                            $state = 'id_name';
                            $context = 'extends';
                            $lastState = 'anonymous';
                        break;
                        case '{':
                            $state = 'closure';
                            if (!$inside_anonymous) {
                                $inside_anonymous = true;
                                $anonymous_mark = $open;
                            }
                            $i--;
                            break;
                        default:
                            $code .= is_array($token) ? $token[1] : $token;
                    }
                    break;
            }
        }

        if ($isShortClosure) {
            $this->useVariables = $this->getStaticVariables();
        } else {
            $this->useVariables = empty($use) ? $use : array_intersect_key($this->getStaticVariables(), array_flip($use));
        }

        $this->isShortClosure = $isShortClosure;
        $this->isBindingRequired = $isUsingThisObject;
        $this->isScopeRequired = $isUsingScope;
        $this->code = $code;

        return $this->code;
    }

    /**
     * @return array
     */
    public function getUseVariables(): array
    {
        if ($this->isInternal()) {
            return [];
        }

        if($this->useVariables !== null){
            return $this->useVariables;
        }

        $tokens = $this->getTokens();

        $use = [];
        $state = 'start';

        foreach ($tokens as &$token) {
            $is_array = is_array($token);

            switch ($state) {
                case 'start':
                    if ($is_array && $token[0] === T_USE) {
                        $state = 'use';
                    }
                    break;
                case 'use':
                    if ($is_array) {
                        if ($token[0] === T_VARIABLE) {
                            $use[] = substr($token[1], 1);
                        }
                    } elseif ($token == ')') {
                        break 2;
                    }
                    break;
            }
        }

        $this->useVariables = empty($use) ? $use : array_intersect_key($this->getStaticVariables(), array_flip($use));

        return $this->useVariables;
    }

    /**
     * return bool
     */
    public function isBindingRequired(): bool
    {
        if ($this->isInternal()) {
            return false;
        }

        if($this->isBindingRequired === null){
            $this->getCode();
        }

        return $this->isBindingRequired ?? false;
    }

    /**
     * return bool
     */
    public function isScopeRequired(): bool
    {
        if ($this->isInternal()) {
            return false;
        }

        if($this->isScopeRequired === null){
            $this->getCode();
        }

        return $this->isScopeRequired ?? false;
    }

    /**
     * @return string
     */
    protected function getHashedFileName(): string
    {
        if ($this->hashedName === null) {
            $this->hashedName = sha1($this->getFileName());
        }

        return $this->hashedName;
    }

    /**
     * @return array
     */
    protected function getFileTokens(): array
    {
        $key = $this->getHashedFileName();

        if (!isset(static::$files[$key])) {
            static::$files[$key] = token_get_all(file_get_contents($this->getFileName()));
        }

        return static::$files[$key];
    }

    /**
     * @return array
     */
    protected function getTokens(): array
    {
        if ($this->tokens === null) {
            $tokens = $this->getFileTokens();
            $startLine = $this->getStartLine();
            $endLine = $this->getEndLine();
            $results = [];
            $start = false;

            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    if ($start) {
                        $results[] = $token;
                    }

                    continue;
                }

                $line = $token[2];

                if ($line <= $endLine) {
                    if ($line >= $startLine) {
                        $start = true;
                        $results[] = $token;
                    }

                    continue;
                }

                break;
            }

            $this->tokens = $results;
        }

        return $this->tokens;
    }

    /**
     * @return array
     */
    protected function getClasses(): array
    {
        $key = $this->getHashedFileName();

        if (!isset(static::$classes[$key])) {
            $this->fetchItems();
        }

        return static::$classes[$key];
    }

    /**
     * @return array
     */
    protected function getFunctions(): array
    {
        $key = $this->getHashedFileName();

        if (!isset(static::$functions[$key])) {
            $this->fetchItems();
        }

        return static::$functions[$key];
    }

    /**
     * @return array
     */
    protected function getConstants(): array
    {
        $key = $this->getHashedFileName();

        if (!isset(static::$constants[$key])) {
            $this->fetchItems();
        }

        return static::$constants[$key];
    }

    /**
     * @return array
     */
    protected function getStructures(): array
    {
        $key = $this->getHashedFileName();

        if (!isset(static::$structures[$key])) {
            $this->fetchItems();
        }

        return static::$structures[$key];
    }

    protected function fetchItems(): void
    {
        $key = $this->getHashedFileName();

        $classes = array();
        $functions = array();
        $constants = array();
        $structures = array();
        $tokens = $this->getFileTokens();

        $open = 0;
        $state = 'start';
        $lastState = '';
        $prefix = '';
        $name = '';
        $alias = '';
        $isFunc = $isConst = false;

        $startLine = $endLine = 0;
        $structType = $structName = '';
        $structIgnore = false;

        foreach ($tokens as $token) {

            switch ($state) {
                case 'start':
                    switch ($token[0]) {
                        case T_CLASS:
                        case T_INTERFACE:
                        case T_TRAIT:
                            $state = 'before_structure';
                            $startLine = $token[2];
                            $structType = $token[0] == T_CLASS
                                                    ? 'class'
                                                    : ($token[0] == T_INTERFACE ? 'interface' : 'trait');
                            break;
                        case T_USE:
                            $state = 'use';
                            $prefix = $name = $alias = '';
                            $isFunc = $isConst = false;
                            break;
                        case T_FUNCTION:
                            $state = 'structure';
                            $structIgnore = true;
                            break;
                        case T_NEW:
                            $state = 'new';
                            break;
                        case T_OBJECT_OPERATOR:
                        case T_DOUBLE_COLON:
                            $state = 'invoke';
                            break;
                    }
                    break;
                case 'use':
                    switch ($token[0]) {
                        case T_FUNCTION:
                            $isFunc = true;
                            break;
                        case T_CONST:
                            $isConst = true;
                            break;
                        case T_NS_SEPARATOR:
                            $name .= $token[1];
                            break;
                        case T_STRING:
                            $name .= $token[1];
                            $alias = $token[1];
                            break;
                        case T_AS:
                            $lastState = 'use';
                            $state = 'alias';
                            break;
                        case '{':
                            $prefix = $name;
                            $name = $alias = '';
                            $state = 'use-group';
                            break;
                        case ',':
                        case ';':
                            if ($name === '' || $name[0] !== '\\') {
                                $name = '\\' . $name;
                            }

                            if ($alias !== '') {
                                if ($isFunc) {
                                    $functions[strtolower($alias)] = $name;
                                } elseif ($isConst) {
                                    $constants[$alias] = $name;
                                } else {
                                    $classes[strtolower($alias)] = $name;
                                }
                            }
                            $name = $alias = '';
                            $state = $token === ';' ? 'start' : 'use';
                            break;
                    }
                    break;
                case 'use-group':
                    switch ($token[0]) {
                        case T_NS_SEPARATOR:
                            $name .= $token[1];
                            break;
                        case T_STRING:
                            $name .= $token[1];
                            $alias = $token[1];
                            break;
                        case T_AS:
                            $lastState = 'use-group';
                            $state = 'alias';
                            break;
                        case ',':
                        case '}':

                            if ($prefix === '' || $prefix[0] !== '\\') {
                                $prefix = '\\' . $prefix;
                            }

                            if ($alias !== '') {
                                if ($isFunc) {
                                    $functions[strtolower($alias)] = $prefix . $name;
                                } elseif ($isConst) {
                                    $constants[$alias] = $prefix . $name;
                                } else {
                                    $classes[strtolower($alias)] = $prefix . $name;
                                }
                            }
                            $name = $alias = '';
                            $state = $token === '}' ? 'use' : 'use-group';
                            break;
                    }
                    break;
                case 'alias':
                    if ($token[0] === T_STRING) {
                        $alias = $token[1];
                        $state = $lastState;
                    }
                    break;
                case 'new':
                    switch ($token[0]) {
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            break 2;
                        case T_CLASS:
                            $state = 'structure';
                            $structIgnore = true;
                            break;
                        default:
                            $state = 'start';
                    }
                    break;
                case 'invoke':
                    switch ($token[0]) {
                        case T_WHITESPACE:
                        case T_COMMENT:
                        case T_DOC_COMMENT:
                            break 2;
                        default:
                            $state = 'start';
                    }
                    break;
                case 'before_structure':
                    if ($token[0] == T_STRING) {
                        $structName = $token[1];
                        $state = 'structure';
                    }
                    break;
                case 'structure':
                    switch ($token[0]) {
                        case '{':
                        case T_CURLY_OPEN:
                        case T_DOLLAR_OPEN_CURLY_BRACES:
                        case T_STRING_VARNAME:
                            $open++;
                            break;
                        case '}':
                            if (--$open == 0) {
                                if(!$structIgnore){
                                    $structures[] = array(
                                        'type' => $structType,
                                        'name' => $structName,
                                        'start' => $startLine,
                                        'end' => $endLine,
                                    );
                                }
                                $structIgnore = false;
                                $state = 'start';
                            }
                            break;
                        default:
                            if (is_array($token)) {
                                $endLine = $token[2];
                            }
                    }
                    break;
            }
        }

        static::$classes[$key] = $classes;
        static::$functions[$key] = $functions;
        static::$constants[$key] = $constants;
        static::$structures[$key] = $structures;
    }
}
