<?php
namespace App\Enum;

enum BlockType: string
{
    case RICHTEXT = 'texte';
    case IMAGE    = 'image';
    case VIDEO    = 'video';
    case FILE     = 'fichier';
    case QUIZ     = 'quiz';
    case CODE     = 'code';
    case CHECKLIST = 'checklist';
}


