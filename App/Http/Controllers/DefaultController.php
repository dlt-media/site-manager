<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateRequest;
use App\Http\Requests\UpdateRequest;
use App\Repositories\SiteRepository;
use App\Services\CloudflareService;
use App\Services\PageruleService;
use App\Services\RecordService;
use App\Services\SiteService;
use Framework\Component\View;
use Framework\Http\JsonResponse;
use Framework\Http\RedirectResponse;
use Framework\Routing\Controller;
use Framework\Support\Collection;
use Framework\Support\Facades\Cache;

class DefaultController extends Controller
{
    /**
     * SiteService instance.
     *
     * @var SiteService
     */
    private SiteService $site_service;

    /**
     * SiteRepository instance.
     *
     * @var SiteRepository
     */
    private SiteRepository $site_repository;

    /**
     * CloudflareService instance.
     *
     * @var CloudflareService
     */
    private CloudflareService $cloudflare_service;

    /**
     * RecordService instance.
     *
     * @var RecordService
     */
    private RecordService $record_service;

    /**
     * PageruleService instance.
     *
     * @var PageruleService
     */
    private PageruleService $pagerule_service;

    /**
     * DefaultController constructor.
     *
     * @return void
     */
    public function __construct(CloudflareService $cloudflare_service, RecordService $record_service, SiteService $site_service, SiteRepository $site_repository, PageruleService $pagerule_service)
    {
        $this->site_service = $site_service;
        $this->record_service = $record_service;
        $this->site_repository = $site_repository;
        $this->cloudflare_service = $cloudflare_service;
        $this->pagerule_service = $pagerule_service;
    }

    /**
     * Default view.
     *
     * @return View
     */
    public function dashboard(): View
    {
        $sites = $this->site_repository->all();

        return view('dashboard.dashboard')
            ->with('domains', $sites->all())
            ->with('cloudflare_service', $this->cloudflare_service);
    }

    /**
     * Clear domain cache.
     *
     * @return RedirectResponse
     */
    public function clear_cache(): RedirectResponse
    {
        Cache::clear();

        return redirect('dashboard')->with('notification', [
            'header' => 'Cache has been cleared',
            'content' => 'Cleared server-side cache and requested refreshed entries.',
            'type' => 'success'
        ]);
    }

    /**
     * Get sites view.
     *
     * @return JsonResponse
     */
    public function sites(): JsonResponse
    {
        $sites = [];

        foreach ($this->site_repository->all()->all() as $site) {
            $sites[] = $site->to_array();
        }

        return response()->json($sites);
    }

    /**
     * Edit domain view.
     *
     * @param string $id
     * @return View
     */
    public function edit(string $id): View
    {
        $site = $this->site_repository->get($id);

        if (!$site) {
            return view('errors.404');
        }

        return view('domain.edit')->with('domain', $site);
    }

    /**
     * Update domain action.
     *
     * @param UpdateRequest $request
     * @param string $id
     * @return RedirectResponse
     */
    public function update(UpdateRequest $request, string $id): RedirectResponse
    {
        $pagerule_input = $request->post('pagerule_forwarding_url');

        if (empty($pagerule_input) && $request->exists('pagerule_forwarding_url')) {
            session()->push('errors.form.pagerule_forwarding_url', 'This field is required');

            return back();
        }

        $site = $this->site_repository->get($id);

        if (!$site) {
            return back()->with('notification', [
                'header' => 'Unable to resolve site option',
                'content' => 'No zone found with given id',
                'type' => 'error',
            ]);
        }

        $root_dns = $this->record_service->update_dns_record($site->id(), [
            'name' => $site->name(),
            'content' => $request->post('root_cname_target'),
        ]);

        if (!$root_dns) {
            session()->push('flash.errors', 'Unable to update root DNS record');
        }

        $sub_dns = $this->record_service->update_dns_record($site->id(), [
            'name' => 'www.' . $site->name(),
            'content' => $request->post('sub_cname_target'),
        ]);

        if (!$sub_dns) {
            session()->push('flash.errors', 'Unable to update sub DNS record');
        }

        if ($request->exists('pagerule_forwarding_url')) {
            $response = $this->pagerule_service->update_pagerules($site->id(), [
                'forwarding_url' => $request->post('pagerule_forwarding_url')
            ]);

            if (!$response) {
                session()->push('flash.errors', 'Unable to update forwarding URL for every pagerule.');
            }
        }

        if (session('flash.errors')) {
            return back()->with('notification', [
                'header' => 'Problems with updating site',
                'content' => 'Failed update request.',
                'type' => 'error'
            ]);
        }

        return back()->with('notification', [
            'header' => 'Updated site',
            'content' => 'Site was updated successfully',
            'type' => 'success',
        ]);
    }

    /**
     * Details domain view.
     *
     * @param string $id
     * @return View
     */
    public function details(string $id): View
    {
        $site = $this->site_repository->get($id);

        return view('domain.details')->with('domain', $site);
    }

    /**
     * Details domain modal.
     *
     * @param string $id
     * @return View
     */
    public function details_modal(string $id): View
    {
        $domain = $this->site_repository->get($id);

        if (!$domain) {
            return view('errors.404');
        }

        $content = view(resource_path('views/domain/details.content.php'), [
            'domain' => $domain
        ]);

        return view(resource_path('views/templates/modal.php'), [
            'title' => 'Details for ' . $domain->name(),
            'content' => $content->render()
        ]);
    }

    /**
     * Add domain view.
     *
     * @return View
     */
    public function add(): View
    {
        return view('domain.add');
    }

    /**
     * Create domain action.
     *
     * @param CreateRequest $request Form request.
     * @return RedirectResponse
     */
    public function create(CreateRequest $request): RedirectResponse
    {
        $response = $this->site_service->add_site([
            'name' => $request->post('domain'),
            'account_id' => config('api.client_id')
        ]);

        if (!empty($response['errors'])) {
            $errors = new Collection($response['errors']);

            if ($errors->contains(fn($error) => $error['code'] === '1061')) {
                return back()->with_errors([
                    'domain' => 'There is another site with the same domain name, unable to have duplicate sites under the same domain name.'
                ]);
            }

            if ($errors->contains(fn($error) => $error['code'] === '1105')) {
                return back()->with_errors([
                    'domain' => 'You attempted to add this domain too many times within a short period. Wait at least 3 hours and try adding it again.'
                ]);
            }

            return back()->with('notification', [
                'header' => 'Unable to add site',
                'content' => 'Unable to add site due to an internal server error.',
                'type' => 'error'
            ]);
        }

        $site = $response['result'];

        $this->site_repository->save($site);

        if (!$this->site_service->set_ssl($site->id(), 'flexible')) {
            session()->push('flash.errors', 'Unable to set SSL to flexible');
        }

        if (!$this->site_service->set_pseudo_ip($site->id(), 'overwrite_header')) {
            session()->push('flash.errors', 'Unable to set pseudo IP to overwrite header');
        }

        if (!$this->site_service->set_https($site->id(), 'on')) {
            session()->push('flash.errors', 'Unable to turn on HTTPS');
        }

        if (!$this->record_service->reset_dns_records($site->id())) {
            session()->push('flash.errors', 'Encountered some issues resetting DNS records due to being unable to delete some DNS records');
        }

        $root_dns = $this->record_service->add_dns_record($site->id(), [
            'name' => '@',
            'content' => $request->post('root_cname_target'),
        ]);

        if (!$root_dns) {
            session()->push('flash.errors', 'Unable to add root DNS record');
        }

        $sub_dns = $this->record_service->add_dns_record($site->id(), [
            'name' => 'www',
            'content' => $request->post('sub_cname_target'),
        ]);

        if (!$sub_dns) {
            session()->push('flash.errors', 'Unable to add sub DNS record');
        }

        if (!$this->pagerule_service->reset_pagerules($site->id())) {
            session()->push('flash.errors', 'Encountered some issues resetting pagerules due to being unable to delete some pagerules');
        }

        $pagerule = $this->pagerule_service->add_pagerule($site->id(), [
            'url' => $request->post('pagerule_url'),
            'forwarding_url' => $request->post('pagerule_forwarding_url')
        ]);

        if (!$pagerule) {
            session()->push('flash.errors', 'Unable to add pagerule URL');
        }

        $pagerule_full = $this->pagerule_service->add_pagerule($site->id(), [
            'url' => $request->post('pagerule_full_url'),
            'forwarding_url' => $request->post('pagerule_forwarding_url')
        ]);

        if (!$pagerule_full) {
            session()->push('flash.errors', 'Unable to add full pagerule URL');
        }

        if (session('flash.errors')) {
            return back()->with('notification', [
                'header' => 'Encountered issues with site setup',
                'content' => 'Site is added, but setup encountered some issues.',
                'type' => 'error'
            ]);
        }

        Cache::clear();

        return back()->with('notification', [
            'header' => 'Added site',
            'content' => 'Site added and setup is done.',
            'type' => 'success',
        ]);
    }

    /**
     * Check nameservers domain action.
     *
     * @param string $id
     * @return RedirectResponse
     */
    public function check_nameservers(string $id): RedirectResponse
    {
        $response = $this->site_service->check_nameservers($id);

        $errors = new Collection($response['errors']);

        if (!$errors->is_empty()) {
            if ($errors->contains(fn($error) => $error['code'] === '1224')) {
                return back()->with('notification', [
                    'header' => 'Unable to check nameservers',
                    'content' => 'This request cannot be made because it can only be called once an hour',
                    'type' => 'error'
                ]);
            }

            return back()->with('notification', [
                'header' => 'Checking nameservers failed',
                'content' => 'Failed to send check nameservers request',
                'type' => 'error'
            ]);
        }

        return back()->with('notification', [
            'header' => 'Started checking nameservers',
            'content' => 'Nameserver check started successfully',
            'type' => 'success',
        ]);
    }
}
