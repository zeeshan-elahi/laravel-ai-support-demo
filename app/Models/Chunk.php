<?php

namespace App\Models;

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

	public function document(): BelongsTo
	{
		return $this->belongsTo(Document::class);
	}
}
