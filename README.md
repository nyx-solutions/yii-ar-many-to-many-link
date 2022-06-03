This extension provides support for ActiveRecord many-to-many relation saving.
For example: single "item" may belong to several "groups", each group may be linked with several items.
Unlike regular [[\yii\db\BaseActiveRecord::link()]] usage, this extension automatically checks references existence,
removes excluded references and provide support for web form composition.

For license information check the [LICENSE](LICENSE.md)-file.

[![Latest Stable Version](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/v/stable)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![Total Downloads](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/downloads)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![Latest Unstable Version](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/v/unstable)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![License](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/license)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![Monthly Downloads](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/d/monthly)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![Daily Downloads](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/d/daily)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)
[![composer.lock](https://poser.pugx.org/nyx-solutions/yii2-nyx-ar-many-to-many-link/composerlock)](https://packagist.org/packages/nyx-solutions/yii2-nyx-ar-many-to-many-link)

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist nyx-solutions/yii2-nyx-ar-many-to-many-link
```

or add

```
"nyx-solutions/yii2-nyx-ar-many-to-many-link": "*"
```

to the require section of your composer.json.


Usage
-----

This extension provides support for ActiveRecord many-to-many relation saving.
This support is granted via [[\nyx\db\LinkManyToManyBehavior]] ActiveRecord behavior. You'll need to attach
it to your ActiveRecord class and point the target "has-many" relation for it:

```php
class Item extends ActiveRecord
{
    public function behaviors()
    {
        return [
            'linkGroupBehavior' => [
                'class' => LinkManyToManyBehavior::className(),
                'relation' => 'groups', // relation, which will be handled
                'relationReferenceAttribute' => 'groupIds', // virtual attribute, which is used for related records specification
            ],
        ];
    }

    public static function tableName()
    {
        return 'Item';
    }

    public function getGroups()
    {
        return $this->hasMany(Group::className(), ['id' => 'groupId'])->viaTable('ItemGroup', ['itemId' => 'id']);
    }
}
```

Being attached [[\nyx\db\LinkManyToManyBehavior]] adds a virtual proprty to the owner ActiveRecord, which
name is determined by [[\nyx\db\LinkManyToManyBehavior::$relationReferenceAttribute]]. You will be able to
specify related models primary keys via this attribute:

```php
// Pick up related model primary keys:
$groupIds = Group::find()
    ->select('id')
    ->where(['isActive' => true])
    ->column();

$item = new Item();
$item->groupIds = $groupIds; // setup related models references
$item->save(); // after main model is saved referred related models are linked
```

The above example is equal to the following code:

```php
$groups = Group::find()
    ->where(['isActive' => true])
    ->all();

$item = new Item();
$item->save();

foreach ($groups as $group) {
    $item->link('groups', $group);
}
```

> Attention: do NOT declare `relationReferenceAttribute` attribute in the owner ActiveRecord class. Make sure it does
  not conflict with any existing owner field or virtual property.

Virtual property declared via `relationReferenceAttribute` serves not only for saving. It also contains existing references
for the relation:

```php
$item = Item::findOne(15);
var_dump($item->groupIds); // outputs something like: array(2, 5, 11)
```

You may as well edit the references list for existing record, while saving linked records will be synchronized:

```php
$item = Item::findOne(15);
$item->groupIds = array_merge($item->groupIds, [17, 21]);
$item->save(); // groups "17" and "21" will be added

$item->groupIds = [5];
$item->save(); // all groups except "5" will be removed
```

> Note: if attribute declared by `relationReferenceAttribute` is never invoked for reading or writing,
  it will not be processed on owner saving. Thus it will not affect pure owner saving.


## Creating relation setup web interface <span id="creating-relation-setup-web-interface"></span>

The main purpose of [[\nyx\db\LinkManyToManyBehavior::$relationReferenceAttribute]] is support for creating
many-to-many setup web interface. All you need to do is declare a validation rule for this virtual property in
your ActiveRecord, so its value can be collected from the request:

```php
class Item extends ActiveRecord
{
    public function rules()
    {
        return [
            ['groupIds', 'safe'] // ensure 'groupIds' value can be collected on `populate()`
            // ...
        ];
    }

    // ...
}
```

Inside the view file you should use `relationReferenceAttribute` property as an attribute name for the form input:

```php
<?php
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $model Item */
?>
<?php $form = ActiveForm::begin(); ?>

<?= $form->field($model, 'name'); ?>
<?= $form->field($model, 'price'); ?>

<?= $form->field($model, 'groupIds')->checkboxList(ArrayHelper::map(Group::find()->all(), 'id', 'name')); ?>

<div class="form-group">
    <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>
```

Inside the controller you don't need any special code:

```php
use yii\web\Controller;

class ItemController extends Controller
{
    public function actionCreate()
    {
        $model = new Item();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view']);
        }

        return $this->render('create', ['model' => $model]);
    }

    // ...
}
```

Details
-------

This extension is based on the extension provided by Paul Klimov (but unmaintened) at 
https://github.com/yii2tech/ar-linkmany.
