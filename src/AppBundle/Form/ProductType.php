<?php

namespace AppBundle\Form;

use AppBundle\Entity\Sylius\ProductOption;
use AppBundle\Enum\Allergen;
use AppBundle\Enum\RestrictedDiet;
use Ramsey\Uuid\Uuid;
use Sylius\Bundle\TaxationBundle\Form\Type\TaxCategoryChoiceType;
use Sylius\Component\Locale\Provider\LocaleProviderInterface;
use Sylius\Component\Product\Factory\ProductVariantFactoryInterface;
use Sylius\Component\Product\Model\Product;
use Sylius\Component\Product\Model\ProductAttributeValue;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;

class ProductType extends AbstractType
{
    private $variantFactory;
    private $variantResolver;
    private $productAttributeRepository;
    private $productAttributeValueFactory;
    private $localeProvider;

    public function __construct(
        ProductVariantFactoryInterface $variantFactory,
        ProductVariantResolverInterface $variantResolver,
        RepositoryInterface $productAttributeRepository,
        FactoryInterface $productAttributeValueFactory,
        LocaleProviderInterface $localeProvider,
        TranslatorInterface $translator)
    {
        $this->variantFactory = $variantFactory;
        $this->variantResolver = $variantResolver;
        $this->productAttributeRepository = $productAttributeRepository;
        $this->productAttributeValueFactory = $productAttributeValueFactory;
        $this->localeProvider = $localeProvider;
        $this->translator = $translator;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.product.name.label'
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'form.product.description.label'
            ])
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'form.product.enabled.label',
            ]);

        $builder->add('allergens', ChoiceType::class, [
            'choices' => $this->createEnumAttributeChoices(Allergen::values(), 'allergens.%s'),
            'label' => 'form.product.allergens.label',
            'expanded' => true,
            'multiple' => true,
            'mapped' => false
        ]);

        $builder->add('restrictedDiets', ChoiceType::class, [
            'choices' => $this->createEnumAttributeChoices(RestrictedDiet::values(), 'restricted_diets.%s'),
            'label' => 'form.product.restricted_diets.label',
            'expanded' => true,
            'multiple' => true,
            'mapped' => false
        ]);

        // While price & tax category are defined in ProductVariant,
        // we display the fields at the Product level
        // For now, all variants share the same values
        $builder
            ->add('price', MoneyType::class, [
                'mapped' => false,
                'divisor' => 100,
                'label' => 'form.product.price.label'
            ])
            ->add('taxCategory', TaxCategoryChoiceType::class, [
                'mapped' => false,
                'label' => 'form.product.taxCategory.label'
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {

            $form = $event->getForm();
            $product = $event->getData();

            $form->add('options', EntityType::class, [
                'class' => ProductOption::class,
                'choices' => $product->getRestaurant()->getProductOptions(),
                'expanded' => true,
                'multiple' => true,
            ]);

            if (null !== $product->getId()) {

                $variant = $this->variantResolver->getVariant($product);

                // To keep things simple, all variants have the same price & tax category
                $form->get('price')->setData($variant->getPrice());
                $form->get('taxCategory')->setData($variant->getTaxCategory());
            }

            $this->postSetDataEnumAttribute($product, 'ALLERGENS', $form->get('allergens'));

            $this->postSetDataEnumAttribute($product, 'RESTRICTED_DIETS', $form->get('restrictedDiets'));
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {

            $form = $event->getForm();
            $product = $event->getData();

            $price = $form->get('price')->getData();
            $taxCategory = $form->get('taxCategory')->getData();

            if (null === $product->getId()) {

                $uuid = Uuid::uuid4()->toString();

                $product->setCode($uuid);
                $product->setSlug($uuid);

                $variant = $this->variantFactory->createForProduct($product);

                $variant->setName($product->getName());
                $variant->setCode(Uuid::uuid4()->toString());
                $variant->setPrice($price);
                $variant->setTaxCategory($taxCategory);

                $product->addVariant($variant);

            } else {

                $variant = $this->variantResolver->getVariant($product);

                $variant->setPrice($price);
                $variant->setTaxCategory($taxCategory);
            }

            $this->postSubmitEnumAttribute($product, 'ALLERGENS', $form->get('allergens')->getData());

            $this->postSubmitEnumAttribute($product, 'RESTRICTED_DIETS', $form->get('restrictedDiets')->getData());
        });
    }

    private function createEnumAttributeChoices(array $values, $format)
    {
        $choices = [];
        foreach ($values as $value) {
            $label = $this->translator->trans(sprintf($format, $value->getKey()));
            $choices[$value->getKey()] = $label;
        }

        asort($choices);

        return array_flip($choices);
    }

    private function postSetDataEnumAttribute(Product $product, $attributeCode, FormInterface $form)
    {
        $attributeValue = $product
            ->getAttributeByCodeAndLocale($attributeCode, $this->localeProvider->getDefaultLocaleCode());

        if (null !== $attributeValue) {
            $form->setData($attributeValue->getValue());
        }
    }

    private function postSubmitEnumAttribute(Product $product, $attributeCode, $data)
    {
        $attributeValue = $product
            ->getAttributeByCodeAndLocale($attributeCode, $this->localeProvider->getDefaultLocaleCode());

        if (null === $attributeValue) {
            $attribute =
                $this->productAttributeRepository->findOneBy(['code' => $attributeCode]);
            $attributeValue =
                $this->productAttributeValueFactory->createNew();

            $attributeValue->setAttribute($attribute);
            $attributeValue->setLocaleCode($this->localeProvider->getDefaultLocaleCode());
        }

        $attributeValue->setValue($data);

        $product->addAttribute($attributeValue);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => Product::class,
        ));
    }
}
