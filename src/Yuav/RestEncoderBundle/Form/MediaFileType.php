<?php

namespace Yuav\RestEncoderBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class MediaFileType extends AbstractType
{
	/**
	 * @param FormBuilderInterface $builder
	 * @param array $options
	 */
	public function buildForm(FormBuilderInterface $builder, array $options)
	{
		$builder->add('format')->add('url')->add('label')->add('h264_profile');
		
	}

	/**
	 * @param OptionsResolverInterface $resolver
	 */
	public function setDefaultOptions(OptionsResolverInterface $resolver)
	{
		
		// Format: Determined by the output filename and then video or audio codec. Otherwise: mp4 (for standard outputs); ts (for segmented outputs). 
		
		
		
		$resolver->setDefaults(array('data_class' => 'Yuav\RestEncoderBundle\Entity\MediaFile'));
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return '';
// 		return 'job';
// 		return 'yuav_restencoderbundle_job';
	}
}
