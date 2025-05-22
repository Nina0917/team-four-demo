<?php

namespace App\Models\Deals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Bundle extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'deal_id',
        'payment_type_id',
        'term',
        'minimum_term',
        'amortization_term',
        'rate',
        'residual',
        'msrp_adjustment',
        'down_payment',
        'is_active',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_active'        => 'boolean',
    ];

    /**
     * Get related deal
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Get payment type
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function paymentType()
    {
        return $this->belongsTo('App\Models\Deals\PaymentType', 'payment_type_id', 'id');
    }

    /**
     * calculate payments
     *
     * @return array
     */
    protected function payment(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Cash bundles do not have Term, Rate, Residual Value. It stays the same when payment frequency changes.
                if ($this->paymentType->name == 'cash') {
                    return $this->deal->total;
                }

                $tax_rate = $this->taxRate();

                $msrp_adjusted = $this->selectedBundle ? $this->selectedBundle->msrp_adjustment : 0;

                if ($this->paymentType->name == 'finance') {
                    $number_of_payments = $this->numberOfPayments($this->term);
                    $periodic_interest = $this->periodicInterestRate($number_of_payments);

                    $divisor = (1 - pow(1 + $periodic_interest, -$number_of_payments));

                    $finance_amount_before_tax = $this->deal->vehicle->msrp + $msrp_adjusted - $this->deal->vehicle->discount
                        - $this->deal->rebatesBeforeTax + $this->deal->accessoriesAmount + $this->deal->feesAmount - $this->deal->tradeIn->final_market_value;
                    $finance_amount_after_tax = $finance_amount_before_tax * (1 + $tax_rate);
                    $final_finance_amount = $finance_amount_after_tax + $this->deal->tradeIn->lien_remaining + $this->deal->fedLuxuryTax + $this->deal->bcLuxuryTax - $this->down_payment;

                    if ($divisor == 0) {
                        return $final_finance_amount / $number_of_payments;
                    }

                    $amount = $final_finance_amount * $periodic_interest / $divisor; //  use the Mortgage Loan Amortization Formula
                    return round($amount, 2);
                }

                if ($this->paymentType->name == 'lease') {
                    $lease_amount = $this->deal->vehicle->msrp + $msrp_adjusted - $this->deal->vehicle->discount
                        + $this->deal->accessoriesAmount + $this->deal->feesAmount
                        - $this->deal->rebatesBeforeTax - $this->deal->deposit
                        - $this->down_payment - $this->deal->tradeIn->final_market_value
                        + $this->deal->fedLuxuryTax + $this->deal->bcLuxuryTax + $this->deal->tradeIn->lien_remaining;

                    $taxed_lease_amount = $lease_amount - $this->deal->tradeIn->lien_remaining; // lien remaining is not taxed
                    $base_amount = $this->getLeaseBaseAmount($lease_amount);

                    $taxed_base_amount = $this->getLeaseBaseAmount($taxed_lease_amount); // calculate the tax using the taxed base amount
                    $tax = round($taxed_base_amount * $tax_rate, 2);
                    $amount = $base_amount + $tax;

                    return round($amount, 2);
                }

                return 0;
            }
        );
    }

    /**
     * Calculate tax rate
     *
     * @return float
     */
    public function taxRate()
    {
        $gst_tax = $this->deal->taxProvince->taxes->where('name', 'GST')->first();
        $pst_tax = $this->deal->taxProvince->taxes->where('name', 'PST')->first();

        $rate = 0;
        if ($gst_tax) {
            $rate += $gst_tax->tax_rate / 100;
        }
        if ($pst_tax) {
            $rate += $pst_tax->tax_rate / 100;
        }

        return $rate;
    }

    /**
     * Calculate periodic interest rate
     *
     * @param int $number_of_payments
     *
     * @return float
     */
    public function periodicInterestRate(int $number_of_payments)
    {
        $loan_term = $this->term / 12; // the number of years for the loan
        $payment_per_year = $number_of_payments / $loan_term; // the number of payments per year
        return $this->rate / 100 / $payment_per_year;
    }

    /**
     * Calculate payment frequency. For example, if payment frequency is weekly, the user needs to make 52 payments per year
     *
     * @return int
     */
    public function paymentFrequency()
    {
        switch ($this->deal->frequency?->name) {
            case 'weekly':
                return 52;
            case 'bi-weekly':
                return 26;
            case 'monthly':
                return 12;
            default:
                return 1;
        }
    }

    /**
     * Calculate total number of payments. For example, if loam term is 12 months and payment frequency is weekly, the user needs to make
     * 52 * 12 / 12 = 52 payments
     *
     * @param int $term
     *
     * @return int
     */
    public function numberOfPayments(int $term)
    {
        switch ($this->deal->frequency?->name) {
            case 'weekly':
                return 52 * $term / 12;
            case 'bi-weekly':
                return 26 * $term / 12;
            case 'monthly':
                return $term;
            default:
                return 1;
        }
    }

    /**
     * calculate lease base amount
     *
     * @return float
     */
    protected function getLeaseBaseAmount($lease_amount)
    {
        $apr = ($this->rate / 100) / $this->paymentFrequency();
        $number_of_payments = $this->numberOfPayments($this->term);

        if ($apr == 0) {
            return ($lease_amount - $this->residual) / $number_of_payments;
        }

        $left_side_one = $lease_amount;
        $left_side_two = $this->residual / ((1 + $apr) ** $number_of_payments);
        $divisor = ($apr + (1 - (1 / ((1 + $apr) ** ($number_of_payments - 1)))));
        $right_side_two = $apr / $divisor;
        $amount = (($left_side_one - $left_side_two) * $right_side_two);

        return round($amount, 2);
    }

    /**
     * Calculate lease payment with down payment
     *
     * @return float
     */
    public function leasePaymentWithNoDown()
    {
        $tax_rate = $this->taxRate();

        $msrp_adjusted = $this->selectedBundle ? $this->selectedBundle->msrp_adjustment : 0;

        $lease_amount = $this->deal->vehicle->msrp + $this->msrp_adjustment + $this->deal->accessoriesAmount + $this->deal->feesAmount - $this->deal->rebatesBeforeTax
            - $this->deal->vehicle->discount - $this->deal->tradeIn->final_market_value
            + $this->deal->fedLuxuryTax + $this->deal->bcLuxuryTax + $this->deal->tradeIn->lien_remaining;

        $taxed_lease_amount = $lease_amount - $this->deal->tradeIn->lien_remaining; // lien remaining is not taxed
        $base_amount = $this->getLeaseBaseAmount($lease_amount);

        $taxed_base_amount = $this->getLeaseBaseAmount($taxed_lease_amount); // calculate the tax using the taxed base amount
        $tax = round($taxed_base_amount * $tax_rate, 2);
        $amount = $base_amount + $tax;

        return round($amount, 2);
    }
}
