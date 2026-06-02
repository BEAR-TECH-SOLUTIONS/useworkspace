<?php

namespace App\Enums;

enum FeedbackCategory: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Question = 'question';
    case Other = 'other';
}
