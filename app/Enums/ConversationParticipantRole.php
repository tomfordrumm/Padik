<?php

namespace App\Enums;

enum ConversationParticipantRole: string
{
    case Member = 'member';
    case Owner = 'owner';
    case Moderator = 'moderator';
}
