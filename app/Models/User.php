<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Áreas de Trabalho de que o usuário é dono. */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /** Itens compartilhados com este usuário (Fase 2). */
    public function sharedWorkspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')->withPivot(['permission', 'invited_by'])->withTimestamps();
    }

    public function sharedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withPivot(['permission', 'invited_by'])->withTimestamps();
    }

    public function sharedNotebooks(): BelongsToMany
    {
        return $this->belongsToMany(Notebook::class, 'notebook_members')->withPivot(['permission', 'invited_by'])->withTimestamps();
    }

    public function aiMessages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /** Notas de outros usuários compartilhadas com este usuário. */
    public function sharedNotes(): BelongsToMany
    {
        return $this->belongsToMany(Note::class, 'note_collaborators')
            ->withPivot(['permission', 'invited_by'])
            ->withTimestamps();
    }

    public function diaryEntries(): HasMany
    {
        return $this->hasMany(DiaryEntry::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    /** Preferências de UI mescladas com os valores padrão. */
    public function prefs(): array
    {
        $defaults = [
            'chatPosition' => 'side',
            'chatWidth' => 372,
            'chatHeight' => 320,
            'chatCollapsed' => false,
            'assistantName' => 'Assistente',
            'assistantAvatar' => 'default',
            'workdayStart' => '09:00',
            'workdayEnd' => '18:00',
            'workspaceGrouping' => 'merged',
            'notebookGrouping' => 'merged',
            'activityRange' => 'day',
            'teamActivityEnabled' => false,
        ];

        return array_merge($defaults, (array) ($this->preferences ?? []));
    }

    /** URL pública da foto de perfil, ou null se o usuário não enviou uma. */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'bio',
        'avatar_path',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }
}
