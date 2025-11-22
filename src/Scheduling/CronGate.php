<?php

namespace MastersRadio\Top100\Scheduling;

use MastersRadio\Top100\Logger;

/**
 * Determines if script should run based on schedule (last Monday of month)
 */
class CronGate
{
    private Logger $logger;
    private string $timezone;

    public function __construct(Logger $logger, string $timezone = 'America/New_York')
    {
        $this->logger = $logger;
        $this->timezone = $timezone;
        date_default_timezone_set($timezone);
    }

    /**
     * Check if today is the last Monday of the current month
     */
    public function shouldRun(): bool
    {
        $now = new \DateTime('now', new \DateTimeZone($this->timezone));
        
        return $this->isLastMonday($now);
    }

    /**
     * Determine if given date is the last Monday of its month
     */
    private function isLastMonday(\DateTime $date): bool
    {
        // Check if today is Monday (1 = Monday in ISO-8601)
        if ((int)$date->format('N') !== 1) {
            $this->logger->debug("Not running: Today is not Monday (day: " . $date->format('l') . ")");
            return false;
        }

        // Get the last day of current month
        $lastDayOfMonth = (int)$date->format('t');
        $currentDay = (int)$date->format('j');
        
        // Get the last Monday of this month
        $lastMonday = $this->getLastMondayOfMonth($date);
        $lastMondayDay = (int)$lastMonday->format('j');

        // Check if today is the last Monday
        if ($currentDay === $lastMondayDay) {
            $this->logger->info("Schedule gate passed: Today is the last Monday of " . $date->format('F Y'));
            return true;
        }

        $this->logger->debug("Not running: Today is not the last Monday (current: {$currentDay}, last Monday: {$lastMondayDay})");
        return false;
    }

    /**
     * Get the last Monday of a given month
     */
    private function getLastMondayOfMonth(\DateTime $date): \DateTime
    {
        // Create a copy to avoid modifying original
        $lastMonday = clone $date;
        
        // Go to last day of month
        $lastMonday->modify('last day of this month');
        
        // If last day is not Monday, go back to previous Monday
        while ((int)$lastMonday->format('N') !== 1) {
            $lastMonday->modify('-1 day');
        }
        
        return $lastMonday;
    }

    /**
     * Get the label month for current run (YYYY-MM format)
     */
    public function getLabelMonth(): string
    {
        $now = new \DateTime('now', new \DateTimeZone($this->timezone));
        return $now->format('Y-m');
    }

    /**
     * Get next scheduled run date
     */
    public function getNextRunDate(): \DateTime
    {
        $now = new \DateTime('now', new \DateTimeZone($this->timezone));
        
        // Get last Monday of current month
        $lastMonday = $this->getLastMondayOfMonth($now);
        
        // If we've already passed it this month, get next month's
        if ($now > $lastMonday) {
            $nextMonth = clone $now;
            $nextMonth->modify('first day of next month');
            $lastMonday = $this->getLastMondayOfMonth($nextMonth);
        }
        
        // Set time to 04:00
        $lastMonday->setTime(4, 0, 0);
        
        return $lastMonday;
    }
}
