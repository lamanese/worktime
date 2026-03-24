<?php

declare(strict_types=1);

namespace OCA\WorkTime\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getEmployeeId()
 * @method void setEmployeeId(int $employeeId)
 * @method DateTime getValidFrom()
 * @method void setValidFrom(DateTime $validFrom)
 * @method string getMonHours()
 * @method void setMonHours(string $monHours)
 * @method string getTueHours()
 * @method void setTueHours(string $tueHours)
 * @method string getWedHours()
 * @method void setWedHours(string $wedHours)
 * @method string getThuHours()
 * @method void setThuHours(string $thuHours)
 * @method string getFriHours()
 * @method void setFriHours(string $friHours)
 * @method string getSatHours()
 * @method void setSatHours(string $satHours)
 * @method string getSunHours()
 * @method void setSunHours(string $sunHours)
 * @method int getVacationDays()
 * @method void setVacationDays(int $vacationDays)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class WorkSchedule extends Entity implements JsonSerializable {

    protected int $employeeId = 0;
    protected ?DateTime $validFrom = null;
    protected string $monHours = '8.00';
    protected string $tueHours = '8.00';
    protected string $wedHours = '8.00';
    protected string $thuHours = '8.00';
    protected string $friHours = '8.00';
    protected string $satHours = '0.00';
    protected string $sunHours = '0.00';
    protected int $vacationDays = 30;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('employeeId', 'integer');
        $this->addType('validFrom', 'datetime');
        $this->addType('vacationDays', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Sum of all 7 day hours.
     */
    public function getWeeklyHours(): float {
        return (float)$this->monHours
            + (float)$this->tueHours
            + (float)$this->wedHours
            + (float)$this->thuHours
            + (float)$this->friHours
            + (float)$this->satHours
            + (float)$this->sunHours;
    }

    /**
     * Get hours for a specific day of week.
     *
     * @param int $dow ISO day of week: 1=Monday..7=Sunday
     */
    public function getHoursForDayOfWeek(int $dow): float {
        return match ($dow) {
            1 => (float)$this->monHours,
            2 => (float)$this->tueHours,
            3 => (float)$this->wedHours,
            4 => (float)$this->thuHours,
            5 => (float)$this->friHours,
            6 => (float)$this->satHours,
            7 => (float)$this->sunHours,
            default => 0.0,
        };
    }

    /**
     * Count days with >0 hours.
     */
    public function getWorkingDaysPerWeek(): int {
        $count = 0;
        for ($i = 1; $i <= 7; $i++) {
            if ($this->getHoursForDayOfWeek($i) > 0) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if a specific day of week is a working day.
     */
    public function isWorkingDay(int $dow): bool {
        return $this->getHoursForDayOfWeek($dow) > 0;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'employeeId' => $this->employeeId,
            'validFrom' => $this->validFrom?->format('Y-m-d'),
            'monHours' => (float)$this->monHours,
            'tueHours' => (float)$this->tueHours,
            'wedHours' => (float)$this->wedHours,
            'thuHours' => (float)$this->thuHours,
            'friHours' => (float)$this->friHours,
            'satHours' => (float)$this->satHours,
            'sunHours' => (float)$this->sunHours,
            'weeklyHours' => $this->getWeeklyHours(),
            'vacationDays' => $this->vacationDays,
            'workingDaysPerWeek' => $this->getWorkingDaysPerWeek(),
            'createdAt' => $this->createdAt?->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }
}
