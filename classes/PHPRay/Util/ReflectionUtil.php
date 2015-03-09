<?php
/**
 * Created by PhpStorm.
 * User: panzd
 * Date: 15/3/2
 * Time: 上午10:10
 */

namespace PHPRay\Util;

use Nette\Reflection\ClassType;
use Nette\Reflection\Method;


class ReflectionUtil {
    const WATCH_MAX_DEPTH = 10;
    const WATCH_MAX_CHILDREN = 100;

    const ACCESSIBLE_PUBLIC = 1;
    const ACCESSIBLE_PROTECTED = 2;
    const ACCESSIBLE_PRIVATE = 3;

    /**
     * @param $file
     * @return string[]
     */
    public static function fetchClassesFromFile($file) {
        $classes = array();

        $php_code = file_get_contents ( $file );
        $namespace="";
        $tokens = token_get_all ( $php_code );
        $count = count ( $tokens );

        for($i = 0; $i < $count; $i ++)
        {
            if ($tokens[$i][0]===T_NAMESPACE)
            {
                for ($j=$i+1;$j<$count;++$j)
                {
                    if ($tokens[$j][0]===T_STRING)
                        $namespace.="\\".$tokens[$j][1];
                    elseif ($tokens[$j]==='{' or $tokens[$j]===';')
                        break;
                }
            }
            if ($tokens[$i][0]===T_CLASS)
            {
                for ($j=$i+1;$j<$count;++$j)
                    if ($tokens[$j]==='{')
                    {
                        $classes[]=$namespace."\\".$tokens[$i+2][1];
                    }
            }
        }

        return array_unique($classes);
    }

    public static function fetchClassesAndMethodes($file) {
        $classes = self::fetchClassesFromFile($file);

        $ret = array();
        foreach($classes as $class) {
            $classType = new ClassType($class);
            $ret[] = array(
                "name" => $class,
                "description" => self::fetchDocComment($classType->getDocComment()),
                "isBranch" => true,
                "children" => self::getMethodInfos($classType)
            );
        }

        return $ret;
    }

    public static function getMethodInfos(ClassType $class) {
        $methods = $class->getMethods();

        $methodInfos = array();
        foreach($methods as $method) {
            if(!$method->isAbstract() && $method->isPublic()) {
                $methodInfos[] = array(
                    "name" => self::getMethodSign($method),
                    "call" => self::getMethodCall($method, $class->getName()),
                    "shortName" => $method->name,
                    "isStatic" => $method->isStatic(),
                    "isConstructor"=> $method->isConstructor(),
                    "hasTestCase" => $method->hasAnnotation("testCase"),
                    "isGood" => self::isGood($method),
                    "class" => $class->getName(),
                    "description" => self::fetchDocComment($method->getDocComment())
                );
            }
        }

        usort($methodInfos, function($info1, $info2)
        {
            return $info1["shortName"] > $info2["shortName"];
        });

        return $methodInfos;
    }

    public static function getClassTestCode(ClassType $class) {
        $className = $class->getName();

        if($class->hasAnnotation("testCase")) {
            return str_replace("%%", $className, $class->getAnnotation("testCase"));
        } else {
            $method = $class->getConstructor();
            if($method == null) {
                return "return new " . $className . "();";
            }
            else{
                return self::getParametersInit($method) . "return ". self::getMethodCall($method, $className, true) . ";";
            }
        }
    }

    public static function getMethodTestCode(Method $method, $className) {
        if($method->hasAnnotation("testCase")) {
            $methodCode = str_replace("%%", self::getPrefix($method, $className, "instance") . $method->getName(), $method->getAnnotation("testCase"));
        } else {
            $methodCode = self::getParametersInit($method) . "return ". self::getMethodCall($method, $className, true, "instance") . ";";
        }

        return $methodCode;
    }

    public static function watch($var) {
        return self::watchInDepth($var, 1);
    }

    private static function watchInDepth(& $var, $depth) {
        $dump = array();
        if(is_object($var)) {
            $dump["type"] = get_class($var);
            if($depth < self::WATCH_MAX_DEPTH) {
                $dump["children"] = self::dumpObjectChildren($var, $depth);
            } else {
                $dump["value"] = "{...}";
            }
        } else {
            $dump["type"] = gettype($var);
            if(is_array($var)) {
                $dump["size"] = count($var);
                if($depth < self::WATCH_MAX_DEPTH) {
                    $dump["children"] = self::dumpArrayChildren($var, $depth);
                } else {
                    $dump["value"] = "[...]";
                }
            } else {
                if(is_string($var)) {
                    $dump["size"] = strlen($var);
                }
                if(!is_null($var)) {
                    $dump["value"] = var_export($var, true);
                }
            }
        }

        return $dump;
    }

    private static function dumpObjectChildren(& $obj, $depth) {
        $ref = new \ReflectionObject($obj);
        $children = array();
        $properties = $ref->getProperties();
        foreach($properties as $property)
        {
            $name = $property->getName();
            $accessible = $property->isPublic() ? self::ACCESSIBLE_PUBLIC : ($property->isProtected() ? self::ACCESSIBLE_PROTECTED : self::ACCESSIBLE_PRIVATE);

            $property->setAccessible(true);
            $value = $property->getValue($obj);
            $subWatch = self::watchInDepth($value, $depth + 1);
            $subWatch["name"] = $name;
            $subWatch["accessible"] = $accessible;
            if($property->isStatic()) {
                $subWatch["isStatic"] = true;
            }
            $children[$name] = $subWatch;
        }

        $objVars = get_object_vars($obj);
        foreach($objVars as $name => $value) {
            if(array_key_exists($name, $children)) {
                continue;
            }

            $subWatch = self::watchInDepth($value, $depth + 1);
            $children["name"] = $name;
            $children[$name] = $subWatch;
        }

        return array_values($children);
    }

    private static function dumpArrayChildren(& $array, $depth) {
        $children = array();
        $numOfChildren = 0;
        foreach($array as $key => $value) {
            $subWatch = self::watchInDepth($value, $depth + 1);
            $subWatch["name"] = '['. $key . ']';
            $children[] = $subWatch;

            $numOfChildren ++;

            if($numOfChildren >= self::WATCH_MAX_CHILDREN) {
                $children[] = array(
                    "type" => "..."
                );
                break;
            }
        }

        return $children;
    }

    private static function getPrefix(Method $method, $className, $caller) {
        if($method->isConstructor()) {
            return "";
        }

        if($method->isStatic()) {
            return $className . "::";
        }

        $prefix = "";
        if(!empty($caller)) {
            $prefix = "\$" . $caller;
        }

        return $prefix. "->";
    }

    private static function getParametersInit(Method $method) {
        $code = "";
        $parameters = $method->getParameters();
        foreach($parameters as $parameter) {
            $code .= "\$" . $parameter->getName() . " = ";

            if($parameter->isDefaultValueAvailable()) {
                $code .= var_export($parameter->getDefaultValue(), true);
            } else {
                $code .= "null";
            }

            $code .= ";" . PHP_EOL;
        }

        return $code . PHP_EOL;
    }

    private static function isGood(Method $method) {
        return $method->name[0] == "_" || $method->getAnnotation("good");
    }

    private static function getMethodCall(Method $method, $className, $reserveDefault = false, $caller="") {
        $parameters = $method->getParameters();

        if($method->isConstructor()) {
            $call = "new " . $className;
        } else {
            $call = self::getPrefix($method, $className, $caller) . $method->getName();
        }

        $call .= "(";
        $first = true;
        foreach($parameters as $parameter) {
            if(!$parameter->isDefaultValueAvailable() || $reserveDefault) {
                if($first) {
                    $first = false;
                } else {
                    $call .= ", ";
                }

                $paramName = $parameter->getName();
                $call .= "\$" . $paramName;
            }
        }

        $call .= ")";

        return $call;
    }

    private static function getMethodSign(Method $method) {
        $parameters = $method->getParameters();

        $paramTypes = self::getParamTypes($method);

        $sign = $method->getName() . "(";
        $first = true;
        foreach($parameters as $parameter) {
            if($first) {
                $first = false;
            } else {
                $sign .= ", ";
            }

            $paramName = $parameter->getName();
            $className = $parameter->getClassName();
            if(!$className && array_key_exists($paramName, $paramTypes)) {
                $className = $paramTypes[$paramName];
            }

            if($className) {
                $sign .= $className . " ";
            }

            $sign .= "\$" . $paramName;

            if($parameter->isDefaultValueAvailable()) {
                $sign .= " = ";
                $value = $parameter->getDefaultValue();
                if(is_object($value)) {
                    $sign .= "object";
                } else if(is_array($value)) {
                    $sign .= "array";
                } else {
                    $sign .= var_export($value, true);
                }
            }
        }

        $sign .= ") : " . ( $method->isConstructor() ? $method->getDeclaringClass()->getName() : self::getReturnType($method));

        return $sign;
    }

    private static function getParamTypes(Method $method) {
        $paramTypes = array();
        $annotations = $method->getAnnotations();
        if(array_key_exists("param", $annotations)) {
            $params = $annotations["param"];
            foreach($params as $param) {
                $matches = array();
                if(preg_match("/([^\\s]*)\\s*\\\$([^\\s]+)/", $param, $matches)) {
                    $type = $matches[1];
                    $paramName = $matches[2];

                    $paramTypes[$paramName] = $type;
                }
            }
        }

        return $paramTypes;
    }

    private static function getReturnType(Method $method) {
        $annotations = $method->getAnnotations();

        if(array_key_exists("return", $annotations)) {
            $params = $annotations["return"];
            $matches = array();
            if(preg_match("/^[^\\s]+/", $params[0], $matches)) {
                return $matches[0];
            }
        }

        return "mixed";
    }

    private static function fetchDocComment($comment)
    {
        return preg_replace('#^\s*\*\s?#ms', '', trim($comment, '/*'));
    }
}