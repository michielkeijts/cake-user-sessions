<?php
declare(strict_types=1);

namespace UserSessions\Model\Entity;

use Cake\ORM\Entity;

/**
 * UserSession Entity
 *
 * @property string $id
 * @property string|null $session_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $useragent
 * @property string $ip
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $accessed
 * @property \Cake\I18n\DateTime|null $expires
 *
 * @property \UserSession\Model\Entity\User $user
 * @property \UserSession\Model\Entity\Phinxlog[] $phinxlog
 */
class UserSession extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'session_id' => true,
        'user_id' => true,
        'name' => true,
        'useragent' => true,
        'ip' => true,
        'created' => true,
        'modified' => true,
        'accessed' => true,
        'expires' => true,
        'user' => true,
        'phinxlog' => true,
    ];
}
