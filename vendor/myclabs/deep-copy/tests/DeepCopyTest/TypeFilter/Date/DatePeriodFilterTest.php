<?php declare(strict_types=1);

namespace DeepCopyTest\TypeFilter\Date;

use DateInterval;
use DatePeriod;
use DateTime;
use DeepCopy\TypeFilter\Date\DateIntervalFilter;
use DeepCopy\TypeFilter\Date\DatePeriodFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DeepCopy\TypeFilter\Date\DatePeriodFilter
 */
class DatePeriodFilterTest extends TestCase
{
    public function test_it_deep_copies_a_DatePeriod()
    {
        $object = new DatePeriod(new DateTime(), new DateInterval('P2D'), 3);

        $filter = new DatePeriodFilter();

        $copy = $filter->apply($object);

        $this->assertEquals($object, $copy);
        $this->assertNotSame($object, $copy);
    }

    public function test_it_deep_copies_a_DatePeriod_with_exclude_start_date()
    {
        $object = new DatePeriod(new DateTime(), new DateInterval('P2D'), 3, DatePeriod::EXCLUDE_START_DATE);

        $filter = new DatePeriodFilter();

        $copy = $filter->apply($object);

        $this->assertEquals($object, $copy);
        $this->assertNotSame($object, $copy);
    }

    /**
     * @requires PHP 8.2
     */
    public function test_it_deep_copies_a_DatePeriod_with_include_end_date()
    {
        $object = new DatePeriod(new DateTime(), new DateInterval('P2D'), 3, DatePeriod::INCLUDE_END_DATE);

        $filter = new DatePeriodFilter();

        $copy = $filter->apply($object);

        $this->assertEquals($object, $copy);
        $this->assertNotSame($object, $copy);
    }

    public function test_it_deep_copies_a_DatePeriod_with_end_date()
    {
        $object = new DatePeriod(new DateTime(), new DateInterval('P2D'), new DateTime('+2 days'));

        $filter = new DatePeriodFilter();

        $copy = $filter->apply($object);

        $this->assertEquals($object, $copy);
        $this->assertNotSame($object, $copy);
    }
}
