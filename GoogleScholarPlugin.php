<?php

/**
 * @file plugins/generic/googleScholar/GoogleScholarPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GoogleScholarPlugin
 *
 * @ingroup plugins_generic_googleScholar
 *
 * @brief Inject Google Scholar meta tags into submission views to facilitate indexing.
 */

namespace APP\plugins\generic\googleScholar;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\GenreDAO;

class GoogleScholarPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('ArticleHandler::view', $this->submissionView(...));
                Hook::add('PreprintHandler::view', $this->submissionView(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Get the name of the settings file to be installed on new context creation.
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Inject Google Scholar metadata into the submission landing page view.
     *
     * @param string $hookName
     * @param array $args
     */
    public function submissionView($hookName, $args): bool
    {
        $application = Application::get();
        $applicationName = $application->getName();

        /** @var Request $request */
        $request = $args[0];
        $issue = null;

        if ($applicationName == 'ojs2') {
            $issue = $args[1];
            $submission = $args[2];
            $submissionPath = 'article';
        }
        if ($applicationName == 'ops') {
            $submission = $args[1];
            $submissionPath = 'preprint';
        }

        /** @var Submission $submission */
        $requestArgs = $request->getRequestedArgs();
        $context = $request->getContext();

        // Only add Google Scholar metadata tags to the canonical URL for the latest version
        // See discussion: https://github.com/pkp/pkp-lib/issues/4870
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('googleScholarRevision', '<meta name="gs_meta_revision" content="1.1"/>');

        // Context identification
        $publication = $submission->getCurrentPublication();
        if ($applicationName == 'ojs2') {
            // OJS 3.6+ stamps the journal identity onto each publication at publish time
            // (pkp/pkp-lib#7527); on OJS 3.5 the helpers fall back to the live context.
            $this->addMetaTag($templateMgr, 'googleScholarJournalTitle', 'citation_journal_title', $this->getPrimaryContextName($publication, $context));

            if ($abbreviation = $this->getPrimaryContextAbbreviation($publication, $context)) {
                $this->addMetaTag($templateMgr, 'googleScholarJournalAbbrev', 'citation_journal_abbrev', $abbreviation);
            }

            if (($issn = $this->getIssn($publication, $context, 'onlineIssn')) || ($issn = $this->getIssn($publication, $context, 'printIssn')) || ($issn = $context->getData('issn'))) {
                $this->addMetaTag($templateMgr, 'googleScholarIssn', 'citation_issn', $issn);
            }

            // Google Scholar lists citation_publisher among the tags it understands for
            // journal articles; OJS previously emitted it for OPS only.
            $this->addMetaTag($templateMgr, 'googleScholarPublisher', 'citation_publisher', $this->getPublisher($publication, $context));
        }

        if ($applicationName == 'ops') {
            $this->addMetaTag($templateMgr, 'googleScholarPublisher', 'citation_publisher', $this->getPrimaryContextName($publication, $context));
        }

        $publicationLocale = $publication->getData('locale');
        $submissionBestId = $submission->getBestId();

        // Contributors
        $authors = $publication->getData('authors');
        $authorIndex = 0;
        foreach ($authors as $author) {
            $this->addMetaTag(
                $templateMgr,
                'googleScholarAuthor' . $authorIndex,
                'citation_author',
                $this->getCitationAuthorName($author, $publicationLocale)
            );

            $affiliationIndex = 0;
            foreach ($author->getAffiliations() as $affiliation) {
                $this->addMetaTag(
                    $templateMgr,
                    'googleScholarAuthor' . $authorIndex . 'Affiliation' . $affiliationIndex++,
                    'citation_author_institution',
                    $affiliation->getLocalizedName($publicationLocale)
                );
            }

            $authorIndex++;
        }

        // Submission title
        $this->addMetaTag($templateMgr, 'googleScholarTitle', 'citation_title', $publication->getLocalizedFullTitle($publicationLocale));
        $this->addMetaTag($templateMgr, 'googleScholarLanguage', 'citation_language', LocaleConversion::toBcp47($publicationLocale));

        // Submission publish date and issue information
        if ($applicationName == 'ojs2') {
            // Resolve the citation date once, then expose it under both the Highwire
            // name OJS has always used and the name Google Scholar documents as required.
            $citationDate = null;
            if (($datePublished = $publication->getData('datePublished')) && (!$issue || !$issue->getYear() || $issue->getYear() == date('Y', strtotime($datePublished)))) {
                $citationDate = date('Y/m/d', strtotime($datePublished));
            } elseif ($issue && $issue->getYear()) {
                $citationDate = (string) $issue->getYear();
            } elseif ($issue && ($datePublished = $issue->getDatePublished())) {
                $citationDate = date('Y/m/d', strtotime($datePublished));
            }

            if ($citationDate !== null) {
                $this->addMetaTag($templateMgr, 'googleScholarDate', 'citation_date', $citationDate);
                $this->addMetaTag($templateMgr, 'googleScholarPublicationDate', 'citation_publication_date', $citationDate);
            }

            if ($issue) {
                if ($issue->getShowVolume()) {
                    $this->addMetaTag($templateMgr, 'googleScholarVolume', 'citation_volume', $issue->getVolume());
                }
                if ($issue->getShowNumber()) {
                    $this->addMetaTag($templateMgr, 'googleScholarNumber', 'citation_issue', $issue->getNumber());
                }
            }

            if ($publication->getData('pages')) {
                if ($startPage = $publication->getStartingPage()) {
                    $this->addMetaTag($templateMgr, 'googleScholarStartPage', 'citation_firstpage', $startPage);
                }
                if ($endPage = $publication->getEndingPage()) {
                    $this->addMetaTag($templateMgr, 'googleScholarEndPage', 'citation_lastpage', $endPage);
                }
            } elseif ($articleNumber = $publication->getData('articleNumber')) {
                // No dedicated tag exists; publishers of article-number journals use citation_firstpage.
                // (articleNumber is only stored by OJS 3.6+; on 3.5 this branch is never taken.)
                $this->addMetaTag($templateMgr, 'googleScholarStartPage', 'citation_firstpage', $articleNumber);
            }
        }

        if ($applicationName == 'ops') {
            if ($datePublished = $publication->getData('datePublished')) {
                $this->addMetaTag($templateMgr, 'googleScholarDate', 'citation_online_date', date('Y/m/d', strtotime($datePublished)));
            }
        }

        // DOI
        if ($doi = $publication->getDoi()) {
            $this->addMetaTag($templateMgr, 'googleScholarPublicationDOI', 'citation_doi', $doi);
        }

        // URN
        foreach ((array) $templateMgr->getTemplateVars('pubIdPlugins') as $pubIdPlugin) {
            if ($pubId = $publication->getStoredPubId($pubIdPlugin->getPubIdType())) {
                $this->addMetaTag(
                    $templateMgr,
                    'googleScholarPubId' . $pubIdPlugin->getPubIdDisplayType(),
                    'citation_' . strtolower($pubIdPlugin->getPubIdDisplayType()),
                    $pubId
                );
            }
        }

        // Abstract URL
        $this->addMetaTag(
            $templateMgr,
            'googleScholarHtmlUrl',
            'citation_abstract_html_url',
            $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'view', [$submissionBestId], urlLocaleForPage: '')
        );

        // Abstract
        if ($abstract = $publication->getLocalizedData('abstract', $publicationLocale)) {
            $this->addMetaTag(
                $templateMgr,
                'googleScholarAbstract',
                'citation_abstract',
                $abstract,
                ['xml:lang' => LocaleConversion::toBcp47($publicationLocale)]
            );
        }

        // Subjects
        // A single counter is used across all locales: the per-locale array index
        // used previously produced colliding header keys, so the tags emitted for
        // one locale silently overwrote those of another.
        if ($subjects = $publication->getData('subjects')) {
            $subjectIndex = 0;
            foreach ($subjects as $locale => $localeSubjects) {
                foreach ($localeSubjects as $subject) {
                    $this->addMetaTag(
                        $templateMgr,
                        'googleScholarSubject' . $subjectIndex++,
                        'citation_keywords',
                        $subject,
                        ['xml:lang' => LocaleConversion::toBcp47($locale)]
                    );
                }
            }
        }

        // Keywords
        if ($keywords = $publication->getData('keywords')) {
            $keywordIndex = 0;
            foreach ($keywords as $locale => $localeKeywords) {
                foreach ($localeKeywords as $keyword) {
                    $this->addMetaTag(
                        $templateMgr,
                        'googleScholarKeyword' . $keywordIndex++,
                        'citation_keywords',
                        $keyword,
                        ['xml:lang' => LocaleConversion::toBcp47($locale)]
                    );
                }
            }
        }

        // Galley links
        //
        // citation_pdf_url and citation_fulltext_html_url are single-valued in the
        // Highwire tag set Google Scholar consumes, but a publication frequently
        // carries more than one galley of a given type: a data set, a translated
        // extended abstract, or a full text issued in a second language. Emitting
        // one tag per galley left Scholar to guess which file is the version of
        // record, so exactly one galley of each type is selected here.
        //
        // Selection rules, in order:
        //   1. Galleys whose file uses a supplementary or dependent genre are
        //      never eligible. Editors control this through the file's component.
        //   2. Among the remaining galleys, one whose language matches the
        //      publication locale wins, since that is the language the rest of the
        //      citation_* metadata describes.
        //   3. Otherwise the first eligible galley wins, i.e. the editor's galley
        //      order decides.
        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $contextId = $context?->getId();

        $pdfGalley = null;
        $htmlGalley = null;

        // Note: getData('galleys') is a LazyCollection, so it must be iterated
        // directly. Casting it with (array) would expose the collection's own
        // properties instead of its items.
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            $submissionFileId = $galley->getData('submissionFileId');
            if (!$submissionFileId || !($submissionFile = Repo::submissionFile()->get($submissionFileId))) {
                continue;
            }

            $genre = $genreDao->getById($submissionFile->getData('genreId'), $contextId);
            if ($genre && ($genre->getSupplementary() || $genre->getDependent())) {
                continue;
            }

            switch ($submissionFile->getData('mimetype')) {
                case 'application/pdf':
                    $pdfGalley = $this->preferGalley($pdfGalley, $galley, $publicationLocale);
                    break;
                case 'text/html':
                    $htmlGalley = $this->preferGalley($htmlGalley, $galley, $publicationLocale);
                    break;
            }
        }

        if ($pdfGalley) {
            $this->addMetaTag(
                $templateMgr,
                'googleScholarPdfUrl',
                'citation_pdf_url',
                $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'download', [$submissionBestId, $pdfGalley->getBestGalleyId()], urlLocaleForPage: '')
            );
        }

        if ($htmlGalley) {
            $this->addMetaTag(
                $templateMgr,
                'googleScholarFulltextHtmlUrl',
                'citation_fulltext_html_url',
                $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'view', [$submissionBestId, $htmlGalley->getBestGalleyId()], urlLocaleForPage: '')
            );
        }

        // Citations
        $citations = $publication->getData('citations') ?? [];
        Hook::call('GoogleScholarPlugin::references', [&$citations, $submission->getId()]);
        $citationIndex = 0;
        foreach ($citations as $citation) {
            $this->addMetaTag($templateMgr, 'googleScholarReference' . $citationIndex++, 'citation_reference', $citation->getRawCitation());
        }

        return false;
    }

    /**
     * Build the value for a citation_author tag.
     *
     * Google Scholar splits the tag value on whitespace and treats the last token
     * as the surname, which mangles any surname made up of more than one word.
     * Scholar's inclusion guidelines allow the unambiguous "Family, Given" form,
     * so the name is assembled from the stored name parts rather than taken from
     * the concatenated full name.
     */
    protected function getCitationAuthorName($author, ?string $publicationLocale): string
    {
        $givenName = $author->getGivenName($publicationLocale);
        if (empty($givenName)) {
            $givenName = $author->getLocalizedGivenName();
        }

        $familyName = $author->getFamilyName($publicationLocale);
        if (empty($familyName)) {
            $familyName = $author->getLocalizedFamilyName();
        }

        // Some callers return the full localized array when no value exists for
        // the requested locale.
        if (is_array($givenName)) {
            $givenName = reset($givenName);
        }
        if (is_array($familyName)) {
            $familyName = reset($familyName);
        }

        $givenName = trim((string) $givenName);
        $familyName = trim((string) $familyName);

        if ($familyName === '') {
            return $givenName;
        }
        if ($givenName === '') {
            return $familyName;
        }

        return $familyName . ', ' . $givenName;
    }

    /**
     * Journal/server name for citation_journal_title / citation_publisher.
     *
     * OJS/OPS 3.6 stamp the context identity onto the publication so that the
     * name in effect at publication time is used; OJS 3.5 has no such field, so
     * the live context name in its primary locale is used there.
     */
    protected function getPrimaryContextName(Publication $publication, Context $context): ?string
    {
        if (method_exists($publication, 'getPrimaryContextName')) {
            return $publication->getPrimaryContextName($context);
        }
        return $context->getName($context->getPrimaryLocale());
    }

    /**
     * Journal abbreviation (or acronym) for citation_journal_abbrev, using the
     * stamped identity on OJS 3.6+ and the live context on OJS 3.5.
     */
    protected function getPrimaryContextAbbreviation(Publication $publication, Context $context): ?string
    {
        if (method_exists($publication, 'getPrimaryContextAbbreviation')) {
            return $publication->getPrimaryContextAbbreviation($context);
        }
        $primaryLocale = $context->getPrimaryLocale();
        return $context->getData('abbreviation', $primaryLocale) ?: $context->getData('acronym', $primaryLocale) ?: null;
    }

    /**
     * Online or print ISSN ($type is 'onlineIssn' or 'printIssn'), using the
     * stamped identity on OJS 3.6+ and the live context on OJS 3.5.
     */
    protected function getIssn(Publication $publication, Context $context, string $type): ?string
    {
        $method = 'get' . ucfirst($type);
        if (method_exists($publication, $method)) {
            return $publication->{$method}($context);
        }
        return $context->getData($type) ?: null;
    }

    /**
     * Publisher for citation_publisher on journals, using the stamped identity on
     * OJS 3.6+ and the context's publisher institution on OJS 3.5.
     */
    protected function getPublisher(Publication $publication, Context $context): ?string
    {
        if (method_exists($publication, 'getPublisher')) {
            return $publication->getPublisher($context);
        }
        return $context->getData('publisherInstitution') ?: null;
    }

    /**
     * Decide which of two galleys of the same type should be advertised to
     * Google Scholar.
     *
     * The first eligible galley is kept unless a later one is in the language the
     * publication's metadata describes, which makes the galley order set by the
     * editor the tie-breaker for single-language publications and the publication
     * locale the tie-breaker for publications issued in more than one language.
     */
    protected function preferGalley(?Galley $current, Galley $candidate, ?string $publicationLocale): Galley
    {
        if ($current === null) {
            return $candidate;
        }

        if ($publicationLocale === null) {
            return $current;
        }

        $language = fn ($locale): string => strtolower(explode('_', str_replace('-', '_', (string) $locale))[0]);
        $wanted = $language($publicationLocale);

        if ($language($candidate->getData('locale')) === $wanted && $language($current->getData('locale')) !== $wanted) {
            return $candidate;
        }

        return $current;
    }

    /**
     * Register a meta tag in the page header, skipping it when there is no value.
     *
     * @param array<string, string> $extraAttributes Additional attributes, e.g. ['xml:lang' => 'tr']
     */
    protected function addMetaTag(TemplateManager $templateMgr, string $key, string $name, mixed $content, array $extraAttributes = []): void
    {
        $content = $this->normalizeMetaContent($content);
        if ($content === '') {
            return;
        }

        $attributes = '';
        foreach ($extraAttributes as $attributeName => $attributeValue) {
            $attributeValue = $this->normalizeMetaContent($attributeValue);
            if ($attributeValue === '') {
                continue;
            }
            $attributes .= ' ' . $attributeName . '="' . htmlspecialchars($attributeValue, ENT_QUOTES, 'UTF-8') . '"';
        }

        $templateMgr->addHeader(
            $key,
            '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
                . $attributes
                . ' content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '"/>'
        );
    }

    /**
     * Turn a stored metadata value into plain text suitable for a meta tag.
     *
     * Markup is removed, HTML entities are resolved (so that sequences such as
     * "&nbsp;" do not survive into the tag as literal text) and all whitespace,
     * including line breaks and non-breaking spaces, is collapsed to single
     * spaces.
     */
    protected function normalizeMetaContent(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === false) {
            return '';
        }

        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking and zero-width spaces are whitespace as far as a meta tag
        // is concerned, but preg_replace('/\s+/') does not treat them as such.
        $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Get the display name of this plugin
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.googleScholar.name');
    }

    /**
     * Get the description of this plugin
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.googleScholar.description');
    }
}

// OJS 3.5 still defines PKP_STRICT_MODE and may rely on the legacy global alias;
// OJS 3.6 removed the constant (pkp/pkp-lib#11583), so it must not be referenced unguarded.
if (defined('PKP_STRICT_MODE') && !PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\googleScholar\GoogleScholarPlugin', '\GoogleScholarPlugin');
}
