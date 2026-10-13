<?php

declare(strict_types=1);

namespace App\Repository\Onboarding;

use App\Entity\Onboarding\Answer;
use App\Entity\User;
use App\Enum\Onboarding\Goal;
use App\Enum\Onboarding\Level;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Answer>
 */
class AnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Answer::class);
    }

    /**
     * The user's answers, as raw values (no entity: the row is written in SQL).
     *
     * @return array{level: Level|null, goal: Goal|null}
     */
    public function of(User $user): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT level, goal FROM onboarding_answer WHERE user_id = ?',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );

        $level = false === $row ? null : $row['level'];
        $goal = false === $row ? null : $row['goal'];

        return [
            'level' => \is_string($level) ? Level::tryFrom($level) : null,
            'goal' => \is_string($goal) ? Goal::tryFrom($goal) : null,
        ];
    }

    /**
     * Replaces the user's answers (upsert: two tabs saving at once never collide).
     */
    public function save(User $user, ?Level $level, ?Goal $goal, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO onboarding_answer (user_id, level, goal, updated_at) VALUES (?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE level = new.level, goal = new.goal, updated_at = new.updated_at',
            [$user->getId()->toBinary(), $level?->value, $goal?->value, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            [ParameterType::BINARY],
        );
    }
}
