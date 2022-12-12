<?php

namespace AppBundle\Form;

use AppBundle\Entity\TimeLog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TimeLogType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('description', TextareaType::class, array('required' => true, 'label' => 'Motif', 'attr' => array('class' => 'materialize-textarea')));
        $builder->add('time', NumberType::class, array('required' => true, 'label' => 'Valeur négative ou positive en minutes'));
        $builder->add('date', DateType::class,array('required' => true,
            'input' => 'datetime',
            'data' => new \DateTime(),
            'widget' => 'single_text',
            'label' => 'Date effet',
            'attr' => [
                'class' => 'datepicker'
            ]
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => TimeLog::class
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'appbundle_note';
    }


}
