<?php

class HookService {

    public static function callHook($vertical, $hookMethod, $data) {
        $hookClass = __DIR__."/../verticals/".ucfirst($vertical)."Hook.php";
        if(file_exists($hookClass)) {
            require_once $hookClass;
            $className = ucfirst($vertical)."Hook";
            if(method_exists($className,$hookMethod)) {
                return $className::$hookMethod($data);
            }
        }
        return $data; // no hook, return as is
    }
}
