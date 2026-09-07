<?php

declare(strict_types=1);

namespace Adelia;

enum FilterOperator: string
{
    case Equal = '=';
    case NotEqual = '<>';
    case Greater = '>';
    case All = 'AND';
    case Any = 'OR';
}
