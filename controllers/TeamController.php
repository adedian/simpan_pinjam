<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Team;
use App\Services\Auth;
use App\Services\RuleViolation;
use App\Services\TeamService;

final class TeamController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->view($request, 'master/teams', ['title' => 'Data Ketua Regu', 'teams' => Team::all()]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        return $this->form($request, null, ['name' => '', 'leader_member_id' => '', 'is_active' => 1]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        [$errors, $data] = TeamService::parse($request->post);
        if ($errors === []) {
            try {
                TeamService::create($request, Auth::user(), $data);
                Session::flash('success', 'Regu berhasil ditambahkan.');
                return $this->redirect('/master/ketua-regu');
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/master/ketua-regu/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $team = Team::find((int) $params['id']);
        if ($team === null) {
            $this->abort(404);
        }
        return $this->form($request, $team, ['name' => $team['name'], 'leader_member_id' => (string) $team['leader_member_id'], 'is_active' => (int) $team['is_active']]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (Team::find($id) === null) {
            $this->abort(404);
        }
        [$errors, $data] = TeamService::parse($request->post);
        if ($errors === []) {
            try {
                TeamService::update($request, Auth::user(), $id, $data, (string) $request->input('_version', ''));
                Session::flash('success', 'Data regu disimpan.');
                return $this->redirect('/master/ketua-regu');
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/master/ketua-regu/' . $id . '/ubah', $errors, $request->post);
    }

    /**
     * @param array<string,mixed>|null $team
     * @param array<string,mixed> $defaults
     */
    private function form(Request $request, ?array $team, array $defaults): Response
    {
        $old = $_SESSION['_old'] ?? [];
        return $this->view($request, 'master/team-form', [
            'title'      => $team === null ? 'Tambah Regu' : 'Ubah Regu',
            'team'       => $team,
            'values'     => $old !== [] ? $old : $defaults,
            'candidates' => Team::leaderCandidates($team === null ? null : (int) $team['id']),
            'action'     => $team === null ? url('/master/ketua-regu') : url('/master/ketua-regu/' . (int) $team['id']),
            'version'    => $team['updated_at'] ?? '',
        ]);
    }
}
