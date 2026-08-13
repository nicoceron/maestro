<?php

namespace App\Enums;

enum AttendanceOutcome: string
{
    case Present = 'present';
    case Late = 'late';
    case AbsentExcused = 'absent_excused';
    case AbsentUnexcused = 'absent_unexcused';
    case NoShow = 'no_show';
    case TeacherCancelled = 'teacher_cancelled';
}
