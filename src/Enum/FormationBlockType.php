<?php


// src/Enum/FormationBlockType.php
namespace App\Enum;

enum FormationBlockType: string {
    case RICHTEXT = 'richtext';
    case IMAGE    = 'image';
    case VIDEO    = 'video';     // URL (YouTube/Vimeo…)
    case FILE     = 'file';      // Pièce jointe
    case CODE     = 'code';
    case CHECKLIST= 'checklist'; // items en JSON
    case QUIZ     = 'quiz';      // JSON (questions)
}
