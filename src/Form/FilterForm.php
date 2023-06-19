<?php

namespace DH\AuditorBundle\Form;

use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class FilterForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $supportedFilters = Query::getSupportedFilters();
        $classes = 'border rounded py-1 px-3 text-gray-700 mr-4 text-sm';

        foreach ($supportedFilters as $supportedFilter) {
            if ($supportedFilter === 'created_at') {
                $builder->add('created_at_start', DateTimeType::class, [
                    'mapped' => false,
                    'block_prefix' => 'audit_filter',
                    'required' => false,
                    'label' => 'From',
                    'attr' => [
                        'class' => $classes,
                    ],
                ]);
                $builder->add('created_at_end', DateTimeType::class, [
                    'mapped' => false,
                    'block_prefix' => 'audit_filter',
                    'required' => false,
                    'label' => 'To',
                    'attr' => [
                        'class' => $classes,
                    ],
                ]);
            } elseif ($supportedFilter === 'type') {
                $builder->add($supportedFilter, ChoiceType::class, [
                    'mapped' => false,
                    'required' => false,
                    'block_prefix' => 'audit_filter',
                    'choices' => [
                        Transaction::INSERT => Transaction::INSERT,
                        Transaction::UPDATE => Transaction::UPDATE,
                        Transaction::REMOVE => Transaction::REMOVE,
                        Transaction::ASSOCIATE => Transaction::ASSOCIATE,
                        Transaction::DISSOCIATE => Transaction::DISSOCIATE,
                    ],
                    'attr' => [
                        'class' => $classes,
                    ],
                ]);
            } else {
                $builder->add($supportedFilter, TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'block_prefix' => 'audit_filter',
                    'attr' => [
                        'class' => $classes,
                    ],
                ]);
            }
        }
        $builder->add('page', HiddenType::class, [
            'mapped' => false,
            'required' => false,
            'label_render' => false,
            'block_prefix' => 'audit_filter',
        ]);
    }
}
