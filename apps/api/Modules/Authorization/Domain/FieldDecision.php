<?php

namespace Modules\Authorization\Domain;

enum FieldDecision: string
{
    case HIDE = 'hide';
    case READ = 'read';
    case EDIT = 'edit';
}
