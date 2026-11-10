<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
	protected $fillable = [
		'document_id',
		'chunk_index',
		'page_number',
		'content',
		'token_estimate',
		'embedding',
	];

	protected $hidden = [
		'embedding',
	];

	protected function casts(): array
	{
		return [
			'embedding' => AsVector::class,
		];
	}

	/**
	 * Only chunks from documents in one collection ('general' for the cited Q&A,
	 * 'support' for the agent's help center).
	 */
	public function scopeInCollection(Builder $query, string $collection): void
	{
		$query->whereHas('document', fn (Builder $document) => $document->where('collection', $collection));
	}

	public function document(): BelongsTo
	{
		return $this->belongsTo(Document::class);
	}
}
