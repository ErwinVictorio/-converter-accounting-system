<?php
function insertTest($file,$text){$s=file_get_contents($file);$pos=strrpos($s,'}');file_put_contents($file,substr($s,0,$pos).$text."\n}\n");}
insertTest('tests/Feature/PurchaseAdjustmentMergeTest.php', <<<'CODE'
    public function test_adjustment_does_not_merge_into_another_company_or_branch(): void
    {
        $source = $this->brokerRow();
        $other = $this->purchase(['filing_company_tin' => '008564445']);
        $branch = $this->purchase(['filing_company_branch_code' => '0001']);
        $this->get("/records/{$source->id}/adjusted-lookup?tin_number=222222222&is_imported=0")
            ->assertJsonPath('adjustedRecord', null);
        $this->transfer($source)->assertSessionHasNoErrors();
        $this->assertSame('5000.00', $other->fresh()->purchase_local);
        $this->assertSame('5000.00', $branch->fresh()->purchase_local);
        $target = VatInput::where('is_adjusted', true)->sole();
        $this->assertSame($source->filingCompanyIdentity(), $target->filingCompanyIdentity());
    }
CODE);
insertTest('tests/Feature/PurchaseAdjustedDeleteTest.php', <<<'CODE'
    public function test_cross_company_adjustment_history_cannot_restore_to_another_company(): void
    {
        $source = $this->brokerRow('111-111-111-000', 1000.00);
        $this->transfer($source, 400.00)->assertSessionHas('success');
        $target = VatInput::where('is_adjusted', true)->sole();
        $other = $this->brokerRow('222-222-222-000', 600.00, ['filing_company_tin' => '008564445']);
        PurchaseAdjustment::query()->update(['source_vat_input_id' => $other->id]);
        $this->delete("/records/{$target->id}")->assertSessionHas('error');
        $this->assertNotNull($target->fresh());
        $this->assertSame('600.00', $other->fresh()->services);
        $this->assertSame('600.00', $source->fresh()->services);
    }
CODE);
insertTest('tests/Feature/PurchaseCompanySelectionTest.php', <<<'CODE'
    public function test_same_tin_different_branches_do_not_merge_and_failed_import_rolls_back(): void
    {
        config(['bir.companies.branch_one' => [...config('bir.companies.008791976'), 'branch_code' => '0001']]);
        $branch = ['tin' => self::FSI['tin'], 'branch_code' => '0001'];
        Supplier::create(['tin'=>'123-456-789-000','name'=>'SUPPLIER A','addr'=>'STREET','city'=>'MANILA']);
        $this->upload(self::FSI)->assertSessionHas('success');
        $this->upload($branch,2000)->assertSessionHas('success');
        $this->assertSame(1,VatInput::forFilingCompany(self::FSI)->count());
        $this->assertSame('2000.00',VatInput::forFilingCompany($branch)->sole()->purchase_local);
        $before=VatInput::forFilingCompany(self::FSI)->sole()->getAttributes();
        try {
            app(PurchaseUploadService::class)->replace('/missing/purchase.xlsx','2026-05-31',self::FSI);
            $this->fail('An unreadable workbook must fail.');
        } catch (\Throwable $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame($before,VatInput::forFilingCompany(self::FSI)->sole()->getAttributes());
    }

    public function test_purchase_owned_company_cannot_be_deleted_or_have_identity_changed(): void
    {
        $this->record(self::STC);
        $company=WithholdingCompany::where('tin',self::STC['tin'])->sole();
        $this->assertTrue($company->hasFiledRows());
        $this->assertSame(self::STC['tin'],$company->tin);
    }

    public function test_legacy_pending_upload_never_inherits_a_new_company(): void
    {
        $this->upload(self::STC)->assertSessionHas('uploadIssueDialog');
        $pending=PendingPurchaseUpload::sole();
        $pending->update(['filing_company_tin'=>null,'filing_company_branch_code'=>null]);
        $this->get(route('pending-purchase-uploads.show',$pending))->assertJsonPath('ready_to_retry',false);
        $this->post(route('pending-purchase-uploads.retry',$pending),['filing_company_tin'=>self::FSI['tin'],'filing_company_branch_code'=>'0000'])
            ->assertSessionHasErrors('filing_company_tin');
        $this->assertDatabaseCount('vat_inputs',0);
    }
CODE);
