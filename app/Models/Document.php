<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
	protected $fillable = [
		'name',
		'collection',
		'mime_type',
		'path',
		'page_count',
		'chunk_count',
	];

	public function chunks(): HasMany
	{
		return $this->hasMany(Chunk::class)->orderBy('chunk_index');
	}
}
