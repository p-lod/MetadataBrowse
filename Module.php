<?php
namespace MetadataBrowse;

use Omeka\Module\AbstractModule;
use Omeka\Entity\Job;
use Omeka\Entity\Value;
use Omeka\Event\Event;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Form\Form;
use Zend\Mvc\Controller\AbstractController;
use Zend\View\Renderer\PhpRenderer;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;

class Module extends AbstractModule
{

    public function upgrade($oldVersion, $newVersion,
        ServiceLocatorInterface $serviceLocator
    )
    {
        if (version_compare($newVersion, '0.1.1-alpha', '>')) {
            $settings = $serviceLocator->get('Omeka\Settings');
            $settings->delete('metadata_browse_properties');
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }


    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
                'Omeka\Api\Representation\ValueRepresentation',
                Event::REP_VALUE_HTML,
                array($this, 'repValueHtml' )
                );
        
        $sharedEventManager->attach(
                array(
                'Omeka\Controller\Admin\Item',
                'Omeka\Controller\Admin\ItemSet',
                'Omeka\Controller\Site\Item',
                'Omeka\Controller\Site\ItemSet',
                ),
                Event::VIEW_SHOW_AFTER,
                array($this, 'addCSS')
                );
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $params = $controller->params()->fromPost();
        if (isset($params['propertyIds'])) {
            $propertyIds = json_encode($params['propertyIds']);
        } else {
            $propertyIds = json_encode(array());
        }
        $this->settings->set('metadata_browse_properties', $propertyIds);
    }

    public function addCSS($event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/metadata-browse.css', 'MetadataBrowse'));
    }

    public function repValueHtml($event)
    {
        $siteSettings = $this->getServiceLocator()->get('Omeka\SiteSettings');
        $filteredPropertyIds = json_decode($siteSettings->get('metadata_browse_properties'));
        $target = $event->getTarget();
        $propertyId = $target->property()->id();

        $routeMatch = $this->getServiceLocator()->get('Application')
                        ->getMvcEvent()->getRouteMatch();
        $routeMatchParams = $routeMatch->getParams();
        //print_r($routeMatch->getParams());
        //die();
        //setup the route params to pass to the Url helper. Both the route name and its parameters go here
        $routeParams = [
                'action' => 'browse',
        ];
        if ($routeMatch->getParam('__ADMIN__')) {
            $routeParams['route'] = 'admin/default';
        } else {
            $siteSlug = $routeMatch->getParam('site-slug');
            $routeParams['route'] = 'site';
            $routeParams['site-slug'] = $siteSlug . '/' . $target->resource()->getControllerName();
        }
        
        $url = $this->getServiceLocator()->get('ViewHelperManager')->get('Url');
        if (in_array($propertyId, $filteredPropertyIds)) {
            $controllerName = $target->resource()->getControllerName();
            $routeParams['controller'] = $controllerName;
            
            $translator = $this->getServiceLocator()->get('MvcTranslator');
            $params = $event->getParams();
            $html = $params['html'];
            switch ($target->type()) {
                case 'resource':
                    $searchTarget = $target->valueResource()->id();
                    $searchUrl = $this->resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    break;
                case 'uri':
                    $searchTarget = $target->uri();
                    $searchUrl = $this->uriSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    break;
                case 'literal':
                    $searchTarget = $html;
                    $searchUrl = $this->literalSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    break;
                default:
                    $resource = $target->valueResource();
                    $uri = $target->uri();
                    if ($resource) {
                        $searchTarget = $target->valueResource()->id();
                        $searchUrl = $this->resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    } else if ($uri) {
                        $searchUrl = $this->uriSearchUrl($url, $routeParams, $propertyId, $uri);
                    } else {
                        $searchTarget = $html;
                        $searchUrl = $this->literalSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    }
            }

            switch ($controllerName) {
                case 'item':
                    $controllerLabel = 'items';
                break;
                case 'item-set':
                    $controllerLabel = 'item sets';
                break;
                default:
                    $controllerLabel = $controllerName;
                break;
            }
            $text = $translator->translate(sprintf("See all %s with this value", $controllerLabel));
            $link = "<a class='metadata-browse-link' href='$searchUrl'>$text</a>";
            $event->setParam('html', "$html $link");
        }
    }

    protected function literalSearchUrl($url, $routeParams, $propertyId, $searchTarget)
    {
        $searchUrl = $url($routeParams['route'],
              $routeParams,
              array('query' => array('Search' => '',
                                     "property[$propertyId][eq][]" => $searchTarget
                               )
                    )
          );
        return $searchUrl;
    }

    protected function uriSearchUrl($url, $routeParams, $propertyId, $searchTarget)
    {
        $searchUrl = $url($routeParams['route'],
              $routeParams,
              array('query' => array('Search' => '',
                                     "property[$propertyId][eq][]" => $searchTarget
                               )
                    )
          );
        return $searchUrl;
    }

    protected function resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget)
    {
        $searchUrl = $url($routeParams['route'],
              $routeParams,
              array('query' => array('Search' => '',
                                     "property[$propertyId][res][]" => $searchTarget
                               )
                    )
          );
        return $searchUrl;
    }
}
