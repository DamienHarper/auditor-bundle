<?php

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Form\FilterForm;
use DH\AuditorBundle\Helper\UrlHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Overrides DH\AuditorBundle\Controller\ViewerController
 */
class ViewerController extends AbstractController
{
    /**
     * @Route(path="/audit/{entity}/{id}", name="dh_auditor_show_entity_history_2", methods={"GET", "POST"})
     *
     * @param int|string $id
     */
    public function showEntityHistoryAction(Request $request, Reader $reader, string $entity, $id = null): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw $this->createNotFoundException();
        }

        $supportedFilters = Query::getSupportedFilters();
        $form = $this->createForm(FilterForm::class);

        $form->handleRequest($request);

        try {
            $page = (int) $form->get('page')->getData();
            $page = $page < 1 ? 1 : $page;
            $query = $reader->createQuery($entity, [
                'object_id' => $id,
                'page' => $page,
                'page_size' => Reader::PAGE_SIZE,
            ]);

            if ($form->isSubmitted() && $form->isValid()) {
                $min = $form->get('created_at_start')->getData();
                $max = $form->get('created_at_end')->getData();

                if ($min || $max) {
                    $query->addFilter(new DateRangeFilter('created_at', $min, $max));
                }
                foreach ($form->all() as $field) {
                    $data = $field->getData();
                    if (!$data || \in_array($field->getName(), ['page', 'created_at_start', 'created_at_end'])) {
                        continue;
                    }
                    dump($field->getName());
                    dump($data);
                    $query->addFilter(new SimpleFilter($field->getName(), $data));
                }
            }

            $pager = $reader->paginate($query, $page, Reader::PAGE_SIZE);
        } catch (AccessDeniedException $e) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@DHAuditor/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'paginator' => $pager,
            'supportedFilters' => $supportedFilters,
            'form' => $form->createView(),
            'page' => $form->get('page')->getData(),
        ]);
    }
}
