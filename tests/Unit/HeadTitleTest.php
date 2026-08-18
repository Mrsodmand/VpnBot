<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HeadTitleTest extends TestCase
{
    public function test_title_and_body_are_separated_by_one_line_break(): void
    {
        $message = headTitle(' عنوان آزمایشی ') . 'متن پیام';

        $this->assertSame("<b>عنوان آزمایشی</b>\nمتن پیام", $message);
    }
}
