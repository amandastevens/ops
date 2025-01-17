<?php

/**
 * @file pages/preprint/PreprintHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PreprintHandler
 * @ingroup pages_preprint
 *
 * @brief Handle requests for preprint functions.
 *
 */

use PKP\submission\SubmissionFile;
use PKP\submission\PKPSubmission;
use PKP\security\authorization\ContextRequiredPolicy;

use APP\security\authorization\OpsServerMustPublishPolicy;
use APP\template\TemplateManager;
use APP\handler\Handler;

use Firebase\JWT\JWT;

class PreprintHandler extends Handler
{
    /** context associated with the request **/
    public $context;

    /** submission associated with the request **/
    public $preprint;

    /** publication associated with the request **/
    public $publication;

    /** galley associated with the request **/
    public $galley;

    /** fileId associated with the request **/
    public $fileId;


    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        // Permit the use of the Authorization header and an API key for access to unpublished/subscription content
        if ($header = array_search('Authorization', array_flip(getallheaders()))) {
            [$bearer, $jwt] = explode(' ', $header);
            if (strcasecmp($bearer, 'Bearer') == 0) {
                $apiToken = JWT::decode($jwt, Config::getVar('security', 'api_key_secret', ''), ['HS256']);
                // Compatibility with old API keys
                // https://github.com/pkp/pkp-lib/issues/6462
                if (substr($apiToken, 0, 2) === '""') {
                    $apiToken = json_decode($apiToken);
                }
                $this->setApiToken($apiToken);
            }
        }

        $this->addPolicy(new ContextRequiredPolicy($request));
        $this->addPolicy(new OpsServerMustPublishPolicy($request));

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @see PKPHandler::initialize()
     *
     * @param $args array Arguments list
     */
    public function initialize($request, $args = [])
    {
        $urlPath = empty($args) ? 0 : array_shift($args);

        // Get the submission that matches the requested urlPath
        $submission = Services::get('submission')->getByUrlPath($urlPath, $request->getContext()->getId());

        if (!$submission && ctype_digit((string) $urlPath)) {
            $submission = Services::get('submission')->get($urlPath);
            if ($submission && $request->getContext()->getId() != $submission->getContextId()) {
                $submission = null;
            }
        }

        if (!$submission || $submission->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            $request->getDispatcher()->handle404();
        }

        // If the urlPath does not match the urlPath of the current
        // publication, redirect to the current URL
        $currentUrlPath = $submission->getBestId();
        if ($currentUrlPath && $currentUrlPath != $urlPath) {
            $newArgs = $args;
            $newArgs[0] = $currentUrlPath;
            $request->redirect(null, $request->getRequestedPage(), $request->getRequestedOp(), $newArgs);
        }

        $this->preprint = $submission;

        // Get the requested publication or if none requested get the current publication
        $subPath = empty($args) ? 0 : array_shift($args);
        if ($subPath === 'version') {
            $publicationId = (int) array_shift($args);
            $galleyId = empty($args) ? 0 : array_shift($args);
            foreach ((array) $this->preprint->getData('publications') as $publication) {
                if ($publication->getId() === $publicationId) {
                    $this->publication = $publication;
                }
            }
            if (!$this->publication) {
                $request->getDispatcher()->handle404();
            }
        } else {
            $this->publication = $this->preprint->getCurrentPublication();
            $galleyId = $subPath;
        }

        if ($this->publication->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            $request->getDispatcher()->handle404();
        }

        if ($galleyId && in_array($request->getRequestedOp(), ['view', 'download'])) {
            $galleys = (array) $this->publication->getData('galleys');
            foreach ($galleys as $galley) {
                if ($galley->getBestGalleyId() == $galleyId) {
                    $this->galley = $galley;
                    break;
                }
            }
            // Redirect to the most recent version of the submission if the request
            // points to an outdated galley but doesn't use the specific versioned
            // URL. This can happen when a galley's urlPath is changed between versions.
            if (!$this->galley) {
                $publications = $submission->getPublishedPublications();
                foreach ($publications as $publication) {
                    foreach ((array) $publication->getData('galleys') as $galley) {
                        if ($galley->getBestGalleyId() == $galleyId) {
                            $request->redirect(null, $request->getRequestedPage(), $request->getRequestedOp(), [$submission->getBestId()]);
                        }
                    }
                }
                $request->getDispatcher()->handle404();
            }

            // Store the file id if it exists
            if (!empty($args)) {
                $this->fileId = array_shift($args);
            }
        }
    }

    /**
     * View Preprint. (Either preprint landing page or galley view.)
     *
     * @param $args array
     * @param $request Request
     */
    public function view($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        $preprint = $this->preprint;
        $publication = $this->publication;

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'preprint' => $preprint,
            'publication' => $publication,
            'firstPublication' => reset($preprint->getData('publications')),
            'currentPublication' => $preprint->getCurrentPublication(),
            'galley' => $this->galley,
            'fileId' => $this->fileId,
        ]);
        $this->setupTemplate($request);

        $sectionDao = DAORegistry::getDAO('SectionDAO'); /* @var $sectionDao SectionDAO */
        $categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
        $publicationCategories = $categoryDao->getByPublicationId($publication->getId())->toArray();
        $categories = [];
        foreach ($publicationCategories as $category) {
            $title = $category->getLocalizedTitle();
            if ($category->getParentId()) {
                $title = $categoryDao->getById($category->getParentId())->getLocalizedTitle() . ' > ' . $title;
            }
            $categories[] = [
                'path' => $category->getPath(),
                'title' => $title,
            ];
        }

        $templateMgr->assign([
            'ccLicenseBadge' => Application::get()->getCCLicenseBadge($publication->getData('licenseUrl')),
            'publication' => $publication,
            'section' => $sectionDao->getById($publication->getData('sectionId')),
            'categories' => $categories,
        ]);



        if ($this->galley && !$this->userCanViewGalley($request, $preprint->getId(), $this->galley->getId())) {
            fatalError('Cannot view galley.');
        }

        // Get galleys sorted into primary and supplementary groups
        $galleys = $publication->getData('galleys');
        $primaryGalleys = [];
        $supplementaryGalleys = [];
        if ($galleys) {
            $genreDao = DAORegistry::getDAO('GenreDAO');
            $primaryGenres = $genreDao->getPrimaryByContextId($context->getId())->toArray();
            $primaryGenreIds = array_map(function ($genre) {
                return $genre->getId();
            }, $primaryGenres);
            $supplementaryGenres = $genreDao->getBySupplementaryAndContextId(true, $context->getId())->toArray();
            $supplementaryGenreIds = array_map(function ($genre) {
                return $genre->getId();
            }, $supplementaryGenres);

            foreach ($galleys as $galley) {
                $remoteUrl = $galley->getRemoteURL();
                $file = $galley->getFile();
                if (!$remoteUrl && !$file) {
                    continue;
                }
                if ($remoteUrl || in_array($file->getGenreId(), $primaryGenreIds)) {
                    $primaryGalleys[] = $galley;
                } elseif (in_array($file->getGenreId(), $supplementaryGenreIds)) {
                    $supplementaryGalleys[] = $galley;
                }
            }
        }
        $templateMgr->assign([
            'primaryGalleys' => $primaryGalleys,
            'supplementaryGalleys' => $supplementaryGalleys,
        ]);

        // Citations
        if ($publication->getData('citationsRaw')) {
            $citationDao = DAORegistry::getDAO('CitationDAO'); /* @var $citationDao CitationDAO */
            $parsedCitations = $citationDao->getByPublicationId($publication->getId());
            $templateMgr->assign([
                'parsedCitations' => $parsedCitations->toArray(),
            ]);
        }

        // Assign deprecated values to the template manager for
        // compatibility with older themes
        $templateMgr->assign([
            'licenseTerms' => $context->getLocalizedData('licenseTerms'),
            'licenseUrl' => $publication->getData('licenseUrl'),
            'copyrightHolder' => $publication->getLocalizedData('copyrightHolder'),
            'copyrightYear' => $publication->getData('copyrightYear'),
            'pubIdPlugins' => PluginRegistry::loadCategory('pubIds', true),
            'keywords' => $publication->getData('keywords'),
        ]);

        // Fetch and assign the galley to the template
        if ($this->galley && $this->galley->getRemoteURL()) {
            $request->redirectUrl($this->galley->getRemoteURL());
        }

        if (empty($this->galley)) {
            // No galley: Prepare the preprint landing page.

            // Ask robots not to index outdated versions and point to the canonical url for the latest version
            if ($publication->getId() !== $preprint->getCurrentPublication()->getId()) {
                $templateMgr->addHeader('noindex', '<meta name="robots" content="noindex">');
                $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'preprint', 'view', $preprint->getBestId());
                $templateMgr->addHeader('canonical', '<link rel="canonical" href="' . $url . '">');
            }

            if (!HookRegistry::call('PreprintHandler::view', [&$request, &$preprint, $publication])) {
                return $templateMgr->display('frontend/pages/preprint.tpl');
            }
        } else {

            // Ask robots not to index outdated versions
            if ($publication->getId() !== $preprint->getCurrentPublication()->getId()) {
                $templateMgr->addHeader('noindex', '<meta name="robots" content="noindex">');
            }

            // Galley: Prepare the galley file download.
            if (!HookRegistry::call('PreprintHandler::view::galley', [&$request, &$this->galley, &$preprint, $publication])) {
                if ($this->publication->getId() !== $this->preprint->getCurrentPublication()->getId()) {
                    $redirectArgs = [
                        $preprint->getBestId(),
                        'version',
                        $publication->getId(),
                        $this->galley->getBestGalleyId()
                    ];
                } else {
                    $redirectArgs = [
                        $preprint->getId(),
                        $this->galley->getBestGalleyId()
                    ];
                }
                $request->redirect(null, null, 'download', $redirectArgs);
            }
        }
    }

    /**
     * Download an preprint file
     * For deprecated OPS 2.x URLs; see https://github.com/pkp/pkp-lib/issues/1541
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function viewFile($args, $request)
    {
        $preprintId = $args[0] ?? 0;
        $galleyId = $args[1] ?? 0;
        $fileId = isset($args[2]) ? (int) $args[2] : 0;
        header('HTTP/1.1 301 Moved Permanently');
        $request->redirect(null, null, 'download', [$preprintId, $galleyId, $fileId]);
    }

    /**
     * Download an preprint file
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function download($args, $request)
    {
        if (!isset($this->galley)) {
            $request->getDispatcher()->handle404();
        }
        if ($this->galley->getRemoteURL()) {
            $request->redirectUrl($this->galley->getRemoteURL());
        } elseif ($this->userCanViewGalley($request, $this->preprint->getId(), $this->galley->getId())) {
            if (!$this->fileId) {
                $this->fileId = $this->galley->getData('submissionFileId');
            }

            // If no file ID could be determined, treat it as a 404.
            if (!$this->fileId) {
                $request->getDispatcher()->handle404();
            }

            // If the file ID is not the galley's file ID, ensure it is a dependent file, or else 404.
            if ($this->fileId != $this->galley->getFileId()) {
                import('lib.pkp.classes.submission.SubmissionFile'); // Constants
                $dependentFileIds = Services::get('submissionFile')->getIds([
                    'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
                    'assocIds' => [$this->galley->getFileId()],
                    'fileStages' => [SubmissionFile::SUBMISSION_FILE_DEPENDENT],
                    'includeDependentFiles' => true,
                ]);
                if (!in_array($this->fileId, $dependentFileIds)) {
                    $request->getDispatcher()->handle404();
                }
            }

            if (!HookRegistry::call('PreprintHandler::download', [$this->preprint, &$this->galley, &$this->fileId])) {
                $submissionFile = Services::get('submissionFile')->get($this->fileId);

                if (!Services::get('file')->fs->has($submissionFile->getData('path'))) {
                    $request->getDispatcher()->handle404();
                }

                $filename = Services::get('file')->formatFilename($submissionFile->getData('path'), $submissionFile->getLocalizedData('name'));

                $returner = true;
                HookRegistry::call('FileManager::downloadFileFinished', [&$returner]);

                Services::get('file')->download($submissionFile->getData('fileId'), $filename);
            }
        } else {
            header('HTTP/1.0 403 Forbidden');
            echo '403 Forbidden<br>';
        }
    }

    /**
     * Determines whether a user can view this preprint galley or not.
     *
     * @param $request Request
     * @param $preprintId string
     * @param $galleyId int or string
     */
    public function userCanViewGalley($request, $preprintId, $galleyId = null)
    {
        $submission = $this->preprint;
        if ($submission->getStatus() == PKPSubmission::STATUS_PUBLISHED) {
            return true;
        } else {
            $request->redirect(null, 'search');
        }
        return true;
    }

    /**
     * Set up the template. (Load required locale components.)
     *
     * @param $request PKPRequest
     */
    public function setupTemplate($request)
    {
        parent::setupTemplate($request);
        AppLocale::requireComponents(LOCALE_COMPONENT_PKP_READER, LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_APP_SUBMISSION);
    }
}
