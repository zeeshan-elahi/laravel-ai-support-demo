<?php

namespace App\Http\Controllers;

use App\Agent\AgentRunner;
use App\Http\Requests\AskAgentRequest;
use App\Http\Resources\AgentRunResource;

class AgentAskController extends Controller
{
	public function __invoke(AskAgentRequest $request, AgentRunner $agent): AgentRunResource
	{
		$run = $agent->run($request->validated('question'), $request->validated('model'));

		return new AgentRunResource($run->load('steps'));
	}
}
