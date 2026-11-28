<?php

namespace App;

enum OrganizationType: string
{
 case Educational = 'educational';
 case TrainingCenter = 'training_center';
 case Ngo = 'ngo';
 case Employer = 'employer';
 case Donor = 'donor';
 case Government = 'government';
 case Partner = 'partner';
 case Other = 'other';
}
