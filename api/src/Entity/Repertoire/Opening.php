<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Repository\Repertoire\OpeningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named opening position (lichess-org/chess-openings, CC0; loaded by app:repertoire:sync-openings):
 * the name of a repertoire position is found by its normalized FEN digest, no network call.
 * Reference data, written in plain SQL by the synchronization only.
 */
#[ORM\Entity(repositoryClass: OpeningRepository::class, readOnly: true)]
#[ORM\Table(name: 'repertoire_opening')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_opening_epd_hash', columns: ['epd_hash'])]
class Opening
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 3, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $eco;

    #[ORM\Column(length: 255)]
    private string $name;

    /** The usual move order reaching it (SAN, PGN movetext). */
    #[ORM\Column(type: Types::TEXT)]
    private string $pgn;

    /** The same moves in UCI, space separated. */
    #[ORM\Column(type: Types::TEXT, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $uci;

    /** Normalized FEN (App\Chess\Rules). */
    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $epd;

    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $epdHash;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEco(): string
    {
        return $this->eco;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPgn(): string
    {
        return $this->pgn;
    }

    public function getUci(): string
    {
        return $this->uci;
    }

    public function getEpd(): string
    {
        return $this->epd;
    }

    public function getEpdHash(): string
    {
        return $this->epdHash;
    }
}
