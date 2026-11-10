'use client';

import { ClipboardCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import { useDesignStudioComponents } from '../provider/DesignStudioProvider';

export interface DesignStudioReviewsScreenProps {
  /** One line under the title. */
  description?: ReactNode;
  /** Text of the empty state. */
  emptyDescription?: ReactNode;
}

/** Reviews linked to boards (empty for now: review documents come with a later step of the studio). */
export const DesignStudioReviewsScreen = ({
  description = 'Reviews with scores, linked to the boards.',
  emptyDescription = 'Review documents (scores, findings linked to boards, verified / adopted / rejected) come with a later step of the design studio.',
}: DesignStudioReviewsScreenProps) => {
  const { PageHeader, StatusCallout } = useDesignStudioComponents();
  return (
    <>
      <PageHeader title="Reviews" description={description} />
      <StatusCallout icon={ClipboardCheck} title="No reviews imported yet" description={emptyDescription} />
    </>
  );
};
