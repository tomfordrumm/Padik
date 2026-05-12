<?php

namespace App\Enums;

enum ConversationType: string
{
    case General = 'general';
    case Direct = 'direct';
    case Group = 'group';
    case Secret = 'secret';
}
