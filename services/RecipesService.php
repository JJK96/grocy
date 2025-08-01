<?php

namespace Grocy\Services;

use LessQL\Result;

class RecipesService extends BaseService
{
	const RECIPE_TYPE_MEALPLAN_DAY = 'mealplan-day'; // A recipe per meal plan day => name = YYYY-MM-DD

	const RECIPE_TYPE_MEALPLAN_WEEK = 'mealplan-week'; // A recipe per meal plan week => name = YYYY-WW (week number)

	const RECIPE_TYPE_MEALPLAN_SHADOW = 'mealplan-shadow'; // A recipe per meal plan recipe (for separated stock fulfillment checking) => name = YYYY-MM-DD#<meal_plan.id>

	const RECIPE_TYPE_NORMAL = 'normal'; // Normal / manually created recipes

	public function AddNotFulfilledProductsToShoppingList($recipeId, $excludedProductIds = null)
	{
		$recipe = $this->getDataBase()->recipes($recipeId);
		$recipePositions = $this->GetRecipesPosResolved();

		if ($excludedProductIds == null)
		{
			$excludedProductIds = [];
		}

		foreach ($recipePositions as $recipePosition)
		{
			if ($recipePosition->recipe_id == $recipeId && !in_array($recipePosition->product_id, $excludedProductIds))
			{
				$product = $this->getDataBase()->products($recipePosition->product_id);
				$toOrderAmount = round(($recipePosition->missing_amount - $recipePosition->amount_on_shopping_list), 2);

				if ($recipe->not_check_shoppinglist == 1)
				{
					$toOrderAmount = round($recipePosition->missing_amount, 2);
				}

				// When the recipe ingredient option "Only check if any amount is in stock" is enabled,
				// any QU can be used and the amount is not based on qu_stock then
				// => Do the unit conversion here (if any)
				if ($recipePosition->only_check_single_unit_in_stock == 1)
				{
					$conversion = $this->getDatabase()->quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $recipePosition->product_id, $recipePosition->qu_id, $product->qu_id_stock)->fetch();
					if ($conversion != null)
					{
						$toOrderAmount = $toOrderAmount * floatval($conversion->factor);
					}
				}

				if ($toOrderAmount > 0)
				{
					$note = $this->getLocalizationService()->__t('Added for recipe %s', $recipe->name);
					if (!empty($recipePosition->note))
					{
						$note .= "\n" . $recipePosition->note;
					}

					$shoppinglistRow = $this->getDataBase()->shopping_list()->createRow([
						'product_id' => $recipePosition->product_id,
						'amount' => $toOrderAmount,
						'note' => $note
					]);
					$shoppinglistRow->save();
				}
			}
		}
	}

	public function ConsumeRecipe($recipeId)
	{
		if (!$this->RecipeExists($recipeId))
		{
			throw new \Exception('Recipe does not exist');
		}

		$transactionId = uniqid();
		$recipePositions = $this->getDatabase()->recipes_pos_resolved()->where('recipe_id', $recipeId)->fetchAll();

		$this->getDatabaseService()->GetDbConnectionRaw()->beginTransaction();
		try
		{
			foreach ($recipePositions as $recipePosition)
			{
				if ($recipePosition->only_check_single_unit_in_stock == 0)
				{
					$this->getStockService()->ConsumeProduct($recipePosition->product_id, $recipePosition->recipe_amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', $recipeId, null, $transactionId, true, true, true);
				}
			}
		}
		catch (\Exception $ex)
		{
			$this->getDatabaseService()->GetDbConnectionRaw()->rollback();
			throw $ex;
		}
		$this->getDatabaseService()->GetDbConnectionRaw()->commit();

		$recipeRow = $this->getDatabase()->recipes()->where('id = :1', $recipeId)->fetch();
		if (!empty($recipeRow->product_id))
		{
			$product = $this->getDatabase()->products()->where('id = :1', $recipeRow->product_id)->fetch();
			$recipeResolvedRow = $this->getDatabase()->recipes_resolved()->where('recipe_id = :1', $recipeId)->fetch();
			$this->getStockService()->AddProduct($recipeRow->product_id, floatval($recipeRow->desired_servings), null, StockService::TRANSACTION_TYPE_SELF_PRODUCTION, date('Y-m-d'), floatval($recipeResolvedRow->costs_per_serving), null, null, $dummyTransactionId, $product->default_stock_label_type, true);
		}
	}

	public function GetRecipesPosResolved()
	{
		$sql = 'SELECT * FROM recipes_pos_resolved';
		return $this->getDataBaseService()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);
	}

	public function GetRecipesResolved($customWhere = null): Result
	{
		if ($customWhere == null)
		{
			return $this->getDatabase()->recipes_resolved();
		}
		else
		{
			return $this->getDatabase()->recipes_resolved()->where($customWhere);
		}
	}

	public function CopyRecipe($recipeId)
	{
		if (!$this->RecipeExists($recipeId))
		{
			throw new \Exception('Recipe does not exist');
		}

		$newName = $this->getLocalizationService()->__t('Copy of %s', $this->getDataBase()->recipes($recipeId)->name);

		$this->getDatabaseService()->ExecuteDbStatement('INSERT INTO recipes (name, description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id) SELECT \'' . $newName . '\', description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id FROM recipes WHERE id = ' . $recipeId);
		$lastInsertId = $this->getDatabase()->lastInsertId();
		$this->getDatabaseService()->ExecuteDbStatement('INSERT INTO recipes_pos (recipe_id, product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor) SELECT ' . $lastInsertId . ', product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor FROM recipes_pos WHERE recipe_id = ' . $recipeId);
		$this->getDatabaseService()->ExecuteDbStatement('INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) SELECT ' . $lastInsertId . ', includes_recipe_id, servings FROM recipes_nestings WHERE recipe_id = ' . $recipeId);

		return $lastInsertId;
	}

	private function RecipeExists($recipeId)
	{
		$recipeRow = $this->getDataBase()->recipes()->where('id = :1', $recipeId)->fetch();
		return $recipeRow !== null;
	}
}
