<?php

/**
 * Class AtmCustomTemplateFunctions
 *
 * Uses to define custom template functions.
 */
class AtmCustomTemplateFunctions
{
    public static function get(): array
    {
        $functions = [];

        /**
         * Пример функции.
         * Эта функция будет доступна в шаблонах под именем "someFunctionName".
         * Можно использовать параметры любого типа и количества.
         *
         * Пример использования: {{ someFunctionName('string') }}.
         *
         * @param string $someParam
         * @return string
         */
        $functions['someFunctionName'] = fn(string $someParam): string => strtoupper($someParam);

        return $functions;
    }
}
