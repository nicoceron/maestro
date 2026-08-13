<?php

namespace App\Enums;

enum LessonNoteAudience: string
{
    case Student = 'student';
    case Guardian = 'guardian';
    case AuthorPrivate = 'author_private';
}
